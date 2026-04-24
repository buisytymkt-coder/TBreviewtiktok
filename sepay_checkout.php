<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/env.php';

use SePay\Builders\CheckoutBuilder;
use SePay\SePayClient;

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function orderStatusLabel(string $status): string
{
    return match ($status) {
        'pending' => 'Chờ thanh toán',
        'paid', 'success' => 'Đã thanh toán',
        'shipping' => 'Đang giao',
        'cancelled' => 'Đã hủy',
        'failed' => 'Thất bại',
        default => $status,
    };
}

function buildEmbeddedCheckoutHtml(SePayClient $sepay, array $checkoutData, string $environment): string
{
    $checkout = $sepay->checkout();
    $actionUrl = $checkout->getCheckoutUrl($environment);
    $fields = $checkout->generateFormFields($checkoutData);

    $html = '<div class="sepay-embed-wrap">';
    $html .= '<iframe id="sepay-checkout-frame" name="sepay-checkout-frame" class="sepay-iframe" title="SePay Checkout"></iframe>';
    $html .= '<form id="sepay-embed-form" method="POST" action="' . h($actionUrl) . '" target="sepay-checkout-frame" style="display:none">';
    foreach ($fields as $name => $value) {
        $html .= '<input type="hidden" name="' . h((string)$name) . '" value="' . h((string)$value) . '">';
    }
    $html .= '</form>';
    $html .= '<div class="checkout-fallback">';
    $html .= '<button type="button" class="btn" id="open-sepay-new-tab">Mở trang thanh toán ở tab mới</button>';
    $html .= '</div>';
    $html .= '</div>';

    return $html;
}

function getBaseUrl(): string
{
    $https = $_SERVER['HTTPS'] ?? '';
    $scheme = (!empty($https) && $https !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

    return $scriptDir === '' || $scriptDir === '.'
        ? $scheme . '://' . $host
        : $scheme . '://' . $host . $scriptDir;
}

function getCurrentScriptUrl(): string
{
    $https = $_SERVER['HTTPS'] ?? '';
    $scheme = (!empty($https) && $https !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/sepay_checkout.php';
    return $scheme . '://' . $host . $scriptName;
}

function getDb(): SQLite3
{
    $db = new SQLite3(__DIR__ . '/brain.db');
    $db->enableExceptions(true);
    $db->busyTimeout(15000);
    $db->exec('PRAGMA journal_mode = WAL;');
    $db->exec('PRAGMA synchronous = NORMAL;');
    $db->exec('PRAGMA foreign_keys = ON;');

    // Backward-compatible migration for customer email
    $hasEmail = false;
    $columnsRes = $db->query('PRAGMA table_info(customers)');
    while ($col = $columnsRes->fetchArray(SQLITE3_ASSOC)) {
        if (($col['name'] ?? '') === 'email') {
            $hasEmail = true;
            break;
        }
    }
    if (!$hasEmail) {
        $db->exec('ALTER TABLE customers ADD COLUMN email TEXT');
    }

    return $db;
}

function findOrderByInvoice(SQLite3 $db, string $invoice): ?array
{
    if ($invoice === '') {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT o.*, c.name AS customer_name, c.phone AS customer_contact
        , c.email AS customer_email
         FROM orders o
         LEFT JOIN customers c ON c.id = o.customer_id
         WHERE o.invoice_number = :invoice
         LIMIT 1'
    );
    $stmt->bindValue(':invoice', $invoice, SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
    return $row ?: null;
}

function buildCheckoutHtmlForOrder(
    SePayClient $sepay,
    string $environment,
    array $order,
    string $currentScriptUrl
): string {
    $invoiceNumber = trim((string)($order['invoice_number'] ?? ''));
    $amount = (int)round((float)($order['amount'] ?? 0));
    $itemName = trim((string)($order['product_name'] ?? 'Tinh Dầu Tràm Chăm Chăm'));
    $customerName = trim((string)($order['customer_name'] ?? 'Khách hàng'));
    $customerContact = trim((string)($order['customer_contact'] ?? ''));
    $customerEmail = trim((string)($order['customer_email'] ?? ''));

    if ($invoiceNumber === '' || $amount < 2000) {
        throw new RuntimeException('Thiếu invoice hoặc số tiền không hợp lệ để tạo checkout.');
    }

    $description = 'Thanh toán ' . $itemName . ' | ' . $customerName . ' | ' . $customerContact . ($customerEmail !== '' ? (' | ' . $customerEmail) : '');

    $checkoutData = CheckoutBuilder::make()
        ->paymentMethod('BANK_TRANSFER')
        ->currency('VND')
        ->orderInvoiceNumber($invoiceNumber)
        ->orderAmount($amount)
        ->operation('PURCHASE')
        ->orderDescription($description)
        ->successUrl($currentScriptUrl . '?payment=success&invoice=' . urlencode($invoiceNumber))
        ->errorUrl($currentScriptUrl . '?payment=error&invoice=' . urlencode($invoiceNumber))
        ->cancelUrl($currentScriptUrl . '?payment=cancel&invoice=' . urlencode($invoiceNumber))
        ->build();

    return buildEmbeddedCheckoutHtml($sepay, $checkoutData, $environment);
}

function upsertCustomer(SQLite3 $db, string $name, string $contact, string $email = ''): int
{
    $normalizedEmail = filter_var($email, FILTER_VALIDATE_EMAIL)
        ? strtolower($email)
        : (filter_var($contact, FILTER_VALIDATE_EMAIL) ? strtolower($contact) : null);
    $stmt = $db->prepare(
        'SELECT id FROM customers
         WHERE phone = :phone OR (:email IS NOT NULL AND LOWER(email) = :email)
         LIMIT 1'
    );
    $stmt->bindValue(':phone', $contact, SQLITE3_TEXT);
    $stmt->bindValue(':email', $normalizedEmail, $normalizedEmail === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $res = $stmt->execute();
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
    if ($row) {
        $id = (int)$row['id'];
        $up = $db->prepare('UPDATE customers SET name = :name, email = COALESCE(:email, email), updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $up->bindValue(':name', $name, SQLITE3_TEXT);
        $up->bindValue(':email', $normalizedEmail, $normalizedEmail === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $up->bindValue(':id', $id, SQLITE3_INTEGER);
        $up->execute();
        return $id;
    }

    $insert = $db->prepare(
        'INSERT INTO customers(name, phone, zalo, email, registered_at)
         VALUES (:name, :phone, :zalo, :email, CURRENT_TIMESTAMP)'
    );
    $insert->bindValue(':name', $name, SQLITE3_TEXT);
    $insert->bindValue(':phone', $contact, SQLITE3_TEXT);
    $insert->bindValue(':zalo', strpos($contact, '@') !== false ? null : $contact, strpos($contact, '@') !== false ? SQLITE3_NULL : SQLITE3_TEXT);
    $insert->bindValue(':email', $normalizedEmail, $normalizedEmail === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $insert->execute();
    return (int)$db->lastInsertRowID();
}

function createPendingOrder(SQLite3 $db, int $customerId, string $itemName, float $amount, string $invoiceNumber): int
{
    $stmt = $db->prepare(
        'INSERT INTO orders(customer_id, product_id, product_name, amount, status, purchased_at, invoice_number, payment_note)
         VALUES (:customer_id, NULL, :product_name, :amount, :status, CURRENT_TIMESTAMP, :invoice_number, :payment_note)'
    );
    $stmt->bindValue(':customer_id', $customerId, SQLITE3_INTEGER);
    $stmt->bindValue(':product_name', $itemName, SQLITE3_TEXT);
    $stmt->bindValue(':amount', $amount, SQLITE3_FLOAT);
    $stmt->bindValue(':status', 'pending', SQLITE3_TEXT);
    $stmt->bindValue(':invoice_number', $invoiceNumber, SQLITE3_TEXT);
    $stmt->bindValue(':payment_note', 'Tạo từ trang thanh toán', SQLITE3_TEXT);
    $stmt->execute();
    return (int)$db->lastInsertRowID();
}

$db = getDb();

if (($_GET['ajax'] ?? '') === 'order_status') {
    header('Content-Type: application/json; charset=utf-8');
    $invoice = trim((string)($_GET['invoice'] ?? ''));
    $order = findOrderByInvoice($db, $invoice);
    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy đơn hàng']);
        exit;
    }
    echo json_encode([
        'success' => true,
        'invoice_number' => $order['invoice_number'],
        'status' => $order['status'],
        'status_label' => orderStatusLabel((string)$order['status']),
        'amount' => $order['amount'],
        'paid_at' => $order['paid_at'],
    ]);
    exit;
}

$merchantId = trim(appEnv('SEPAY_MERCHANT_ID', ''));
$secretKey = trim(appEnv('SEPAY_MERCHANT_SECRET_KEY', ''));
$environment = trim(appEnv('SEPAY_ENV', 'production'));

$formName = trim((string)($_POST['customer_name'] ?? $_GET['customer_name'] ?? ''));
$formContact = trim((string)($_POST['customer_contact'] ?? $_GET['customer_phone'] ?? ''));
$formEmail = trim((string)($_POST['customer_email'] ?? $_GET['customer_email'] ?? ''));
$formItem = trim((string)($_POST['item_name'] ?? $_GET['package'] ?? $_GET['item_name'] ?? 'Tinh Dầu Tràm Chăm Chăm'));
$formAmount = (float)($_POST['amount'] ?? $_GET['amount'] ?? 2000);

if ($formAmount < 2000) {
    $formAmount = 2000;
}
$checkoutAmount = (int)round($formAmount);

$autoMode = ($_GET['auto'] ?? '') === '1';
$submitted = $_SERVER['REQUEST_METHOD'] === 'POST' || $autoMode;
$paymentState = trim((string)($_GET['payment'] ?? ''));
$invoiceFromQuery = trim((string)($_GET['invoice'] ?? ''));

$errorMessage = '';
$checkoutHtml = '';
$currentOrder = $invoiceFromQuery !== '' ? findOrderByInvoice($db, $invoiceFromQuery) : null;
$resumeCheckout = ($_GET['resume'] ?? '') === '1';

// Reviewer mo link thanh toan thi thay QR ngay.
$autoCreateOnOpen = (
    $_SERVER['REQUEST_METHOD'] !== 'POST' &&
    !$currentOrder &&
    $paymentState === ''
);

if ($autoCreateOnOpen) {
    $submitted = true;
    if ($formName === '') {
        $formName = 'Khách review';
    }
    if ($formContact === '') {
        $formContact = 'review@example.com';
    }
    if ($formItem === '' || $formItem === 'Tinh Dầu Tràm Chăm Chăm') {
        $formItem = 'Thanh toán test SOP';
    }
    if ($formAmount < 2000) {
        $formAmount = 2000;
    }
}

if ($submitted) {
    if ($formName === '' || $formContact === '' || $formItem === '') {
        $errorMessage = 'Vui lòng nhập đầy đủ họ tên, số điện thoại/email và món hàng/dịch vụ.';
    } elseif ($merchantId === '' || $secretKey === '') {
        $errorMessage = 'Bạn chưa cấu hình SEPAY_MERCHANT_ID/SEPAY_MERCHANT_SECRET_KEY trong môi trường.';
    } else {
        $invoiceNumber = 'CC-' . date('YmdHis') . '-' . random_int(100, 999);
        $description = 'Thanh toán ' . $formItem . ' | ' . $formName . ' | ' . $formContact . ($formEmail !== '' ? (' | ' . $formEmail) : '');

        try {
            $inTransaction = false;
            $db->exec('BEGIN IMMEDIATE TRANSACTION');
            $inTransaction = true;
            $customerId = upsertCustomer($db, $formName, $formContact, $formEmail);
            createPendingOrder($db, $customerId, $formItem, $formAmount, $invoiceNumber);
            $db->exec('COMMIT');
            $inTransaction = false;

            $currentScriptUrl = getCurrentScriptUrl();
            $sepay = new SePayClient($merchantId, $secretKey, $environment);
            $checkoutData = CheckoutBuilder::make()
                ->paymentMethod('BANK_TRANSFER')
                ->currency('VND')
                ->orderInvoiceNumber($invoiceNumber)
                ->orderAmount($checkoutAmount)
                ->operation('PURCHASE')
                ->orderDescription($description)
                ->successUrl($currentScriptUrl . '?payment=success&invoice=' . urlencode($invoiceNumber))
                ->errorUrl($currentScriptUrl . '?payment=error&invoice=' . urlencode($invoiceNumber))
                ->cancelUrl($currentScriptUrl . '?payment=cancel&invoice=' . urlencode($invoiceNumber))
                ->build();

            $checkoutHtml = buildEmbeddedCheckoutHtml($sepay, $checkoutData, $environment);
            $currentOrder = findOrderByInvoice($db, $invoiceNumber);
        } catch (Throwable $e) {
            if (!empty($inTransaction)) {
                $db->exec('ROLLBACK');
            }
            $errorMessage = 'Lỗi tạo đơn hàng/thanh toán: ' . $e->getMessage();
        }
    }
}

if (
    $checkoutHtml === '' &&
    $resumeCheckout &&
    $currentOrder &&
    in_array((string)$currentOrder['status'], ['pending', 'failed'], true) &&
    $merchantId !== '' &&
    $secretKey !== ''
) {
    try {
        $sepay = new SePayClient($merchantId, $secretKey, $environment);
        $currentScriptUrl = getCurrentScriptUrl();
        $checkoutHtml = buildCheckoutHtmlForOrder($sepay, $environment, $currentOrder, $currentScriptUrl);
    } catch (Throwable $e) {
        $errorMessage = 'Lỗi tạo lại checkout: ' . $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Checkout SePay - ChamCham</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Inter, Arial, sans-serif;
            color: #1f2937;
            background:
                radial-gradient(circle at 15% 10%, rgba(22, 101, 52, .12), transparent 40%),
                radial-gradient(circle at 85% 90%, rgba(34, 197, 94, .10), transparent 45%),
                #f4f7f5;
        }
        .wrap { max-width: 980px; margin: 28px auto; padding: 0 16px 24px; }
        .card {
            background: #ffffff;
            border: 1px solid #dcfce7;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 18px 36px rgba(22, 101, 52, .12);
        }
        h1 { margin: 0 0 10px; color: #166534; font-size: 48px; line-height: 1.15; }
        .subtitle { margin: 0 0 14px; color: #4b5563; font-size: 16px; }
        .muted { color: #6b7280; font-size: 14px; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; margin-top: 14px; }
        .full { grid-column: 1 / -1; }
        label { display: block; font-size: 14px; font-weight: 700; margin-bottom: 7px; color: #1f2937; }
        input {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 16px;
            background: #fafcfa;
            transition: all .2s ease;
        }
        input:focus {
            outline: none;
            border-color: #16a34a;
            box-shadow: 0 0 0 4px rgba(34, 197, 94, .16);
            background: #fff;
        }
        .btn {
            background: linear-gradient(135deg, #166534, #16a34a);
            color: #fff;
            border: 0;
            border-radius: 999px;
            padding: 13px 22px;
            font-weight: 700;
            font-size: 16px;
            cursor: pointer;
            box-shadow: 0 10px 22px rgba(22, 101, 52, .22);
            transition: transform .2s ease, box-shadow .2s ease;
        }
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 14px 28px rgba(22, 101, 52, .28);
        }
        .alert { border-radius: 12px; padding: 12px 14px; margin: 12px 0; font-size: 15px; }
        .alert.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .alert.warn { background: #fff7ed; color: #9a3412; border: 1px solid #fed7aa; }
        .alert.success { background: #ecfdf3; color: #166534; border: 1px solid #bbf7d0; }
        .status-box {
            margin-top: 16px;
            padding: 14px;
            background: linear-gradient(180deg, #f8fbf9, #f2f8f4);
            border: 1px dashed #b7d7bf;
            border-radius: 12px;
            font-size: 16px;
            line-height: 1.45;
        }
        .checkout-box {
            margin-top: 18px;
            padding: 16px;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            background: #fcfffd;
            box-shadow: inset 0 0 0 1px rgba(255,255,255,.8);
        }
        .checkout-box form {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }
        .checkout-box input,
        .checkout-box select {
            border-radius: 12px;
            border: 1px solid #cfe4d4;
            background: #fff;
        }
        .sepay-embed-wrap { display: grid; gap: 12px; }
        .sepay-iframe {
            width: 100%;
            min-height: 860px;
            border: 1px solid #dbe5dd;
            border-radius: 14px;
            background: #fff;
        }
        .checkout-fallback { display: flex; justify-content: center; }
        .checkout-box button,
        .checkout-box input[type="submit"],
        .checkout-box a[role="button"] {
            appearance: none;
            border: 0;
            border-radius: 999px;
            background: linear-gradient(135deg, #166534, #16a34a);
            color: #fff;
            font-weight: 700;
            font-size: 16px;
            letter-spacing: .1px;
            padding: 13px 24px;
            cursor: pointer;
            text-decoration: none;
            box-shadow: 0 12px 24px rgba(22, 101, 52, .25);
            transition: transform .2s ease, box-shadow .2s ease;
        }
        .checkout-box button:hover,
        .checkout-box input[type="submit"]:hover,
        .checkout-box a[role="button"]:hover {
            transform: translateY(-1px);
            box-shadow: 0 16px 30px rgba(22, 101, 52, .32);
        }
        code { background: #f3f4f6; border-radius: 6px; padding: 2px 6px; }
        @media (max-width: 700px) {
            .grid { grid-template-columns: 1fr; }
            .card { padding: 18px; }
            h1 { font-size: 38px; }
            .checkout-box { padding: 12px; }
            .sepay-iframe { min-height: 780px; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>Trang Thanh Toán</h1>
        <p class="subtitle">Vui lòng điền thông tin bên dưới để tạo đơn hàng và thanh toán nhanh qua SePay.</p>

        <?php if ($paymentState === 'success'): ?>
            <?php if ($currentOrder && in_array((string)$currentOrder['status'], ['paid', 'success'], true)): ?>
                <div class="alert success">Chuyển khoản thành công. Đơn hàng đã được cập nhật thành <strong>Đã thanh toán</strong>.</div>
            <?php else: ?>
                <div class="alert warn">Bạn đã quay lại từ cổng thanh toán. Đơn đang chờ IPN xác nhận, vui lòng đợi vài giây.</div>
            <?php endif; ?>
        <?php elseif ($paymentState === 'error'): ?>
            <div class="alert error">Thanh toán thất bại. Vui lòng thử lại.</div>
        <?php elseif ($paymentState === 'cancel'): ?>
            <div class="alert warn">Bạn đã hủy giao dịch.</div>
        <?php endif; ?>

        <?php if ($errorMessage !== ''): ?>
            <div class="alert error"><?= h($errorMessage) ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="grid">
                <div>
                    <label>Họ và tên</label>
                    <input name="customer_name" required value="<?= h($formName) ?>">
                </div>
                <div>
                    <label>Số điện thoại / Email</label>
                    <input name="customer_contact" required value="<?= h($formContact) ?>">
                </div>
                <div>
                    <label>Email (khuyến nghị)</label>
                    <input name="customer_email" type="email" value="<?= h($formEmail) ?>">
                </div>
                <div class="full">
                    <label>Món hàng / Dịch vụ</label>
                    <input name="item_name" required value="<?= h($formItem) ?>">
                </div>
                <div>
                    <label>Số tiền (VND)</label>
                    <input name="amount" type="number" min="2000" step="1000" value="<?= h((string)$formAmount) ?>">
                </div>
                <div class="full">
                    <button class="btn" type="submit">Tạo đơn hàng và thanh toán</button>
                </div>
            </div>
        </form>

        <?php if ($currentOrder): ?>
            <div class="status-box" id="status-box" data-invoice="<?= h($currentOrder['invoice_number']) ?>">
                <div><strong>Mã hóa đơn:</strong> <code><?= h($currentOrder['invoice_number']) ?></code></div>
                <div><strong>Trạng thái hiện tại:</strong> <span id="order-status"><?= h(orderStatusLabel((string)$currentOrder['status'])) ?></span></div>
                <div><strong>Số tiền:</strong> <?= h((string)$currentOrder['amount']) ?> VND</div>
            </div>
        <?php endif; ?>

        <?php if ($checkoutHtml !== ''): ?>
            <div class="checkout-box">
                <?= $checkoutHtml ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($currentOrder && !in_array((string)$currentOrder['status'], ['paid', 'success'], true)): ?>
<script>
(function () {
    const statusBox = document.getElementById('status-box');
    if (!statusBox) return;
    const invoice = statusBox.getAttribute('data-invoice');
    const statusEl = document.getElementById('order-status');
    if (!invoice || !statusEl) return;

    let attempts = 0;
    const currentPath = window.location.pathname;
    const timer = setInterval(async () => {
        attempts += 1;
        try {
            const res = await fetch(`${currentPath}?ajax=order_status&invoice=${encodeURIComponent(invoice)}`);
            if (!res.ok) return;
            const data = await res.json();
            if (!data.success) return;
            statusEl.textContent = data.status_label || data.status;
            if (data.status === 'paid' || data.status === 'success') {
                clearInterval(timer);
                location.href = `${currentPath}?payment=success&invoice=${encodeURIComponent(invoice)}`;
                return;
            }
        } catch (e) {}

        if (attempts >= 20) {
            clearInterval(timer);
        }
    }, 3000);
})();
</script>
<?php endif; ?>
<?php if ($checkoutHtml !== ''): ?>
<script>
(function () {
    const form = document.getElementById('sepay-embed-form');
    if (form) {
        form.submit();
    }

    const newTabBtn = document.getElementById('open-sepay-new-tab');
    if (!newTabBtn || !form) return;

    newTabBtn.addEventListener('click', () => {
        const tempForm = form.cloneNode(true);
        tempForm.style.display = 'none';
        tempForm.target = '_blank';
        document.body.appendChild(tempForm);
        tempForm.submit();
        tempForm.remove();
    });
})();
</script>
<?php endif; ?>
</body>
</html>
