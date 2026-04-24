<?php

declare(strict_types=1);

session_start();
require_once dirname(__DIR__) . '/lib/env.php';
require_once dirname(__DIR__) . '/lib/resend_mailer.php';

$adminUser = appEnv('ADMIN_USER', '');
$adminPass = appEnv('ADMIN_PASS', '');
if ($adminUser !== '' && $adminPass !== '') {
    $providedUser = (string)($_SERVER['PHP_AUTH_USER'] ?? '');
    $providedPass = (string)($_SERVER['PHP_AUTH_PW'] ?? '');
    $authorized = hash_equals($adminUser, $providedUser) && hash_equals($adminPass, $providedPass);
    if (!$authorized) {
        header('WWW-Authenticate: Basic realm="ChamCham Admin"');
        http_response_code(401);
        echo 'Unauthorized';
        exit;
    }
}

$dbPath = dirname(__DIR__) . '/brain.db';
if (!file_exists($dbPath)) {
    http_response_code(500);
    echo 'Không tìm thấy brain.db';
    exit;
}

$db = new SQLite3($dbPath);
$db->enableExceptions(true);
$db->busyTimeout(15000);
$db->exec('PRAGMA journal_mode = WAL;');
$db->exec('PRAGMA synchronous = NORMAL;');
$db->exec('PRAGMA foreign_keys = ON;');

$customersColumns = [];
$columnsRes = $db->query('PRAGMA table_info(customers)');
while ($col = $columnsRes->fetchArray(SQLITE3_ASSOC)) {
    $customersColumns[] = (string)$col['name'];
}
if (!in_array('email', $customersColumns, true)) {
    $db->exec('ALTER TABLE customers ADD COLUMN email TEXT');
}

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirectTo(string $tab): void
{
    header('Location: /admin/?tab=' . urlencode($tab));
    exit;
}

function setFlash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function getFlash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function toFloat(string $value): float
{
    $normalized = str_replace(',', '.', trim($value));
    return (float)$normalized;
}

function statusLabel(string $status): string
{
    return match ($status) {
        'pending' => 'Chờ thanh toán',
        'success' => 'Đã thanh toán',
        'paid' => 'Đã thanh toán',
        'shipping' => 'Đang giao',
        'cancelled' => 'Đã hủy',
        'failed' => 'Thất bại',
        default => $status,
    };
}

function formatVnd(float $amount): string
{
    return number_format($amount, 0, ',', '.') . 'đ';
}

function sendOrderConfirmationEmail(string $customerEmail, string $customerName, string $productName, float $amount): array
{
    $subject = 'Trâm xác nhận đơn của chị em rồi nè 🌿';
    $safeName = trim($customerName) !== '' ? $customerName : 'chị em';
    $safeProduct = trim($productName) !== '' ? $productName : 'Tinh Dầu Tràm Chăm Chăm';
    $priceText = formatVnd($amount);

    $text = "Chị em ơi,\n\n"
        . "Trâm đã nhận đơn của mình rồi nha.\n\n"
        . "Thông tin đơn hàng:\n"
        . "- Sản phẩm: {$safeProduct}\n"
        . "- Số tiền: {$priceText}\n\n"
        . "Hướng dẫn nhận hàng:\n"
        . "1) Đội ngũ Chăm Chăm sẽ xác nhận lại đơn qua điện thoại.\n"
        . "2) Sau khi xác nhận, bên Trâm đóng gói và gửi hàng sớm.\n"
        . "3) Khi shipper gọi, chị em giúp nghe máy để nhận hàng thuận lợi.\n\n"
        . "Cảm ơn chị em đã tin tưởng Chăm Chăm.\n"
        . "Nếu cần Trâm hỗ trợ thêm cách dùng phù hợp cho mẹ và bé, chị em cứ reply email này nha.\n\n"
        . "Thương,\nTrâm";

    $html = '<p>Chào ' . h($safeName) . ',</p>'
        . '<p>Trâm đã nhận đơn của mình rồi nha.</p>'
        . '<p><strong>Thông tin đơn hàng:</strong><br>'
        . '- Sản phẩm: ' . h($safeProduct) . '<br>'
        . '- Số tiền: ' . h($priceText) . '</p>'
        . '<p><strong>Hướng dẫn nhận hàng:</strong><br>'
        . '1) Đội ngũ Chăm Chăm sẽ xác nhận lại đơn qua điện thoại.<br>'
        . '2) Sau khi xác nhận, bên Trâm đóng gói và gửi hàng sớm.<br>'
        . '3) Khi shipper gọi, chị em giúp nghe máy để nhận hàng thuận lợi.</p>'
        . '<p>Cảm ơn chị em đã tin tưởng Chăm Chăm.<br>'
        . 'Nếu cần Trâm hỗ trợ thêm cách dùng phù hợp cho mẹ và bé, chị em cứ reply email này nha.</p>'
        . '<p>Thương,<br>Trâm</p>';

    $primary = resendSendEmail($customerEmail, $subject, $html, $text);
    if ($primary['ok']) {
        $primary['delivered_to'] = $customerEmail;
        $primary['fallback_used'] = false;
        return $primary;
    }

    $message = (string)($primary['message'] ?? '');
    $isResendSandboxRestriction = $primary['status'] === 403
        && stripos($message, 'You can only send testing emails to your own email address') !== false;

    if (!$isResendSandboxRestriction) {
        $primary['delivered_to'] = null;
        $primary['fallback_used'] = false;
        return $primary;
    }

    $fallbackTo = trim(appEnv('RESEND_TEST_TO', ''));
    if ($fallbackTo === '') {
        $primary['delivered_to'] = null;
        $primary['fallback_used'] = false;
        return $primary;
    }

    $fallback = resendSendEmail($fallbackTo, $subject . ' [Sandbox Fallback]', $html, $text);
    $fallback['delivered_to'] = $fallbackTo;
    $fallback['fallback_used'] = true;
    return $fallback;
}

$allowedTabs = ['products', 'customers', 'orders'];
$tab = $_GET['tab'] ?? 'products';
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'products';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entity = $_POST['entity'] ?? '';
    $op = $_POST['op'] ?? '';

    try {
        if ($entity === 'products') {
            if ($op === 'create' || $op === 'update') {
                $name = trim((string)($_POST['name'] ?? ''));
                $price = toFloat((string)($_POST['price'] ?? '0'));
                $description = trim((string)($_POST['description'] ?? ''));
                $stock = (int)($_POST['stock_quantity'] ?? 0);

                if ($name === '') {
                    throw new RuntimeException('Tên sản phẩm không được để trống.');
                }

                if ($op === 'create') {
                    $stmt = $db->prepare('INSERT INTO products(name, price, description, stock_quantity) VALUES (:name, :price, :description, :stock)');
                } else {
                    $id = (int)($_POST['id'] ?? 0);
                    $stmt = $db->prepare('UPDATE products SET name = :name, price = :price, description = :description, stock_quantity = :stock, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
                }

                $stmt->bindValue(':name', $name, SQLITE3_TEXT);
                $stmt->bindValue(':price', $price, SQLITE3_FLOAT);
                $stmt->bindValue(':description', $description, SQLITE3_TEXT);
                $stmt->bindValue(':stock', $stock, SQLITE3_INTEGER);
                $stmt->execute();

                setFlash($op === 'create' ? 'Đã thêm sản phẩm.' : 'Đã cập nhật sản phẩm.');
            } elseif ($op === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $db->prepare('DELETE FROM products WHERE id = :id');
                $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
                $stmt->execute();
                setFlash('Đã xóa sản phẩm.');
            }
            redirectTo('products');
        }

        if ($entity === 'customers') {
            if ($op === 'create' || $op === 'update') {
                $name = trim((string)($_POST['name'] ?? ''));
                $phone = trim((string)($_POST['phone'] ?? ''));
                $zalo = trim((string)($_POST['zalo'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));
                $registeredAt = trim((string)($_POST['registered_at'] ?? date('Y-m-d H:i:s')));

                if ($name === '' || $phone === '') {
                    throw new RuntimeException('Tên và số điện thoại là bắt buộc.');
                }

                if ($op === 'create') {
                    $stmt = $db->prepare('INSERT INTO customers(name, phone, zalo, email, registered_at) VALUES (:name, :phone, :zalo, :email, :registered_at)');
                } else {
                    $id = (int)($_POST['id'] ?? 0);
                    $stmt = $db->prepare('UPDATE customers SET name = :name, phone = :phone, zalo = :zalo, email = :email, registered_at = :registered_at, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
                }

                $stmt->bindValue(':name', $name, SQLITE3_TEXT);
                $stmt->bindValue(':phone', $phone, SQLITE3_TEXT);
                $stmt->bindValue(':zalo', $zalo === '' ? null : $zalo, $zalo === '' ? SQLITE3_NULL : SQLITE3_TEXT);
                $stmt->bindValue(':email', $email === '' ? null : $email, $email === '' ? SQLITE3_NULL : SQLITE3_TEXT);
                $stmt->bindValue(':registered_at', $registeredAt, SQLITE3_TEXT);
                $stmt->execute();

                setFlash($op === 'create' ? 'Đã thêm khách hàng.' : 'Đã cập nhật khách hàng.');
            } elseif ($op === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $db->prepare('DELETE FROM customers WHERE id = :id');
                $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
                $stmt->execute();
                setFlash('Đã xóa khách hàng.');
            }
            redirectTo('customers');
        }

        if ($entity === 'orders') {
            if ($op === 'create') {
                $customerId = (int)($_POST['customer_id'] ?? 0);
                $productId = (int)($_POST['product_id'] ?? 0);
                $amountInput = trim((string)($_POST['amount'] ?? ''));
                $status = trim((string)($_POST['status'] ?? 'pending'));
                $purchasedAt = trim((string)($_POST['purchased_at'] ?? date('Y-m-d H:i:s')));
                $emailNotice = '';

                if ($customerId <= 0 || $productId <= 0) {
                    throw new RuntimeException('Cần chọn khách hàng và sản phẩm.');
                }

                $db->exec('BEGIN IMMEDIATE TRANSACTION');
                try {
                    $customerStmt = $db->prepare('SELECT id, name, phone, email FROM customers WHERE id = :id');
                    $customerStmt->bindValue(':id', $customerId, SQLITE3_INTEGER);
                    $customerRes = $customerStmt->execute();
                    $customer = $customerRes ? $customerRes->fetchArray(SQLITE3_ASSOC) : null;
                    if (!$customer) {
                        throw new RuntimeException('Không tìm thấy khách hàng.');
                    }

                    $productStmt = $db->prepare('SELECT id, name, price, stock_quantity FROM products WHERE id = :id');
                    $productStmt->bindValue(':id', $productId, SQLITE3_INTEGER);
                    $productRes = $productStmt->execute();
                    $product = $productRes ? $productRes->fetchArray(SQLITE3_ASSOC) : null;

                    if (!$product) {
                        throw new RuntimeException('Không tìm thấy sản phẩm.');
                    }
                    if ((int)$product['stock_quantity'] <= 0) {
                        throw new RuntimeException('Sản phẩm đã hết hàng.');
                    }

                    $amount = $amountInput === '' ? (float)$product['price'] : toFloat($amountInput);
                    $productName = (string)$product['name'];

                    $insertStmt = $db->prepare('INSERT INTO orders(customer_id, product_id, product_name, amount, status, purchased_at) VALUES (:customer_id, :product_id, :product_name, :amount, :status, :purchased_at)');
                    $insertStmt->bindValue(':customer_id', $customerId, SQLITE3_INTEGER);
                    $insertStmt->bindValue(':product_id', $productId, SQLITE3_INTEGER);
                    $insertStmt->bindValue(':product_name', $productName, SQLITE3_TEXT);
                    $insertStmt->bindValue(':amount', $amount, SQLITE3_FLOAT);
                    $insertStmt->bindValue(':status', $status, SQLITE3_TEXT);
                    $insertStmt->bindValue(':purchased_at', $purchasedAt, SQLITE3_TEXT);
                    $insertStmt->execute();

                    $stockStmt = $db->prepare('UPDATE products SET stock_quantity = stock_quantity - 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                    $stockStmt->bindValue(':id', $productId, SQLITE3_INTEGER);
                    $stockStmt->execute();

                    $db->exec('COMMIT');

                    $customerEmail = trim((string)($customer['email'] ?? ''));
                    if ($customerEmail !== '' && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                        $sendResult = sendOrderConfirmationEmail(
                            $customerEmail,
                            (string)($customer['name'] ?? ''),
                            $productName,
                            $amount
                        );
                        if ($sendResult['ok']) {
                            if (!empty($sendResult['fallback_used'])) {
                                $emailNotice = ' Đã gửi email xác nhận về mailbox test (' . ($sendResult['delivered_to'] ?? 'owner') . ') do Resend đang ở test mode.';
                            } else {
                                $emailNotice = ' Đã gửi email xác nhận đơn.';
                            }
                        } else {
                            $emailNotice = ' Đơn đã tạo, nhưng gửi email thất bại: ' . ($sendResult['message'] ?: 'Unknown error');
                        }
                    } else {
                        $emailNotice = ' Đơn đã tạo, chưa gửi email vì khách chưa có email hợp lệ.';
                    }
                } catch (Throwable $inner) {
                    $db->exec('ROLLBACK');
                    throw $inner;
                }

                setFlash('Đã thêm đơn hàng mới và trừ 1 tồn kho sản phẩm.' . $emailNotice);
            } elseif ($op === 'update') {
                $id = (int)($_POST['id'] ?? 0);
                $customerId = (int)($_POST['customer_id'] ?? 0);
                $productId = (int)($_POST['product_id'] ?? 0);
                $amount = toFloat((string)($_POST['amount'] ?? '0'));
                $status = trim((string)($_POST['status'] ?? 'pending'));
                $purchasedAt = trim((string)($_POST['purchased_at'] ?? date('Y-m-d H:i:s')));

                $productStmt = $db->prepare('SELECT name FROM products WHERE id = :id');
                $productStmt->bindValue(':id', $productId, SQLITE3_INTEGER);
                $productRes = $productStmt->execute();
                $product = $productRes ? $productRes->fetchArray(SQLITE3_ASSOC) : null;

                if (!$product) {
                    throw new RuntimeException('Không tìm thấy sản phẩm để cập nhật đơn.');
                }

                $stmt = $db->prepare('UPDATE orders SET customer_id = :customer_id, product_id = :product_id, product_name = :product_name, amount = :amount, status = :status, purchased_at = :purchased_at, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
                $stmt->bindValue(':customer_id', $customerId, SQLITE3_INTEGER);
                $stmt->bindValue(':product_id', $productId, SQLITE3_INTEGER);
                $stmt->bindValue(':product_name', (string)$product['name'], SQLITE3_TEXT);
                $stmt->bindValue(':amount', $amount, SQLITE3_FLOAT);
                $stmt->bindValue(':status', $status, SQLITE3_TEXT);
                $stmt->bindValue(':purchased_at', $purchasedAt, SQLITE3_TEXT);
                $stmt->execute();

                setFlash('Đã cập nhật đơn hàng.');
            } elseif ($op === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $db->prepare('DELETE FROM orders WHERE id = :id');
                $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
                $stmt->execute();
                setFlash('Đã xóa đơn hàng.');
            }
            redirectTo('orders');
        }
    } catch (Throwable $e) {
        setFlash('Lỗi: ' . $e->getMessage(), 'error');
        redirectTo($tab);
    }
}

$mode = $_GET['mode'] ?? '';
$editId = (int)($_GET['id'] ?? 0);
$flash = getFlash();

$products = [];
$customers = [];
$orders = [];

$productRows = $db->query('SELECT * FROM products ORDER BY id DESC');
while ($row = $productRows->fetchArray(SQLITE3_ASSOC)) {
    $products[] = $row;
}

$customerRows = $db->query('SELECT * FROM customers ORDER BY id DESC');
while ($row = $customerRows->fetchArray(SQLITE3_ASSOC)) {
    $customers[] = $row;
}

$orderRows = $db->query(
    'SELECT o.*, c.name AS customer_name, c.phone AS customer_phone
     FROM orders o
     LEFT JOIN customers c ON c.id = o.customer_id
     ORDER BY o.id DESC'
);
while ($row = $orderRows->fetchArray(SQLITE3_ASSOC)) {
    $orders[] = $row;
}

$editRecord = null;
if ($mode === 'edit' && $editId > 0) {
    if ($tab === 'products') {
        $stmt = $db->prepare('SELECT * FROM products WHERE id = :id');
    } elseif ($tab === 'customers') {
        $stmt = $db->prepare('SELECT * FROM customers WHERE id = :id');
    } else {
        $stmt = $db->prepare('SELECT * FROM orders WHERE id = :id');
    }
    $stmt->bindValue(':id', $editId, SQLITE3_INTEGER);
    $res = $stmt->execute();
    $editRecord = $res ? $res->fetchArray(SQLITE3_ASSOC) : null;
}
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản Trị - ChamCham</title>
    <style>
        :root { --green: #166534; --green2: #15803d; --bg: #f5f7f6; --line: #dbe4dc; --text: #1f2937; --muted: #6b7280; --danger: #dc2626; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Inter, Arial, sans-serif; background: var(--bg); color: var(--text); }
        .wrap { max-width: 1200px; margin: 0 auto; padding: 24px; }
        h1 { margin: 0 0 16px; font-size: 28px; }
        .tabs { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
        .tab { padding: 10px 14px; border: 1px solid var(--line); border-radius: 999px; text-decoration: none; color: var(--green); background: #fff; font-weight: 600; }
        .tab.active { background: linear-gradient(135deg, var(--green), var(--green2)); color: #fff; border-color: transparent; }
        .panel { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 16px; box-shadow: 0 10px 24px rgba(0,0,0,.06); }
        .toolbar { display: flex; justify-content: space-between; gap: 12px; align-items: center; margin-bottom: 14px; }
        .btn { border: 0; border-radius: 10px; padding: 8px 12px; cursor: pointer; text-decoration: none; display: inline-block; font-size: 14px; }
        .btn-primary { background: var(--green); color: #fff; }
        .btn-muted { background: #eef4ef; color: var(--green); }
        .btn-danger { background: #fee2e2; color: var(--danger); }
        .flash { padding: 12px 14px; border-radius: 10px; margin-bottom: 14px; }
        .flash.success { background: #ecfdf3; border: 1px solid #bbf7d0; color: var(--green); }
        .flash.error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { border-bottom: 1px solid #edf1ee; text-align: left; padding: 10px 8px; vertical-align: top; }
        th { background: #f8fbf9; color: #374151; }
        .actions { display: flex; gap: 8px; }
        .inline { display: inline; }
        .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-bottom: 14px; }
        .form-grid .full { grid-column: 1 / -1; }
        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
        input, textarea, select { width: 100%; border: 1px solid #cfd8d1; border-radius: 9px; padding: 9px 10px; font-size: 14px; }
        textarea { min-height: 90px; resize: vertical; }
        .muted { color: var(--muted); font-size: 13px; }
        @media (max-width: 900px) {
            .form-grid { grid-template-columns: 1fr; }
            table { display: block; overflow-x: auto; white-space: nowrap; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Trang Quản Trị</h1>
    <div class="tabs">
        <a class="tab <?= $tab === 'products' ? 'active' : '' ?>" href="/admin/?tab=products">Sản phẩm</a>
        <a class="tab <?= $tab === 'customers' ? 'active' : '' ?>" href="/admin/?tab=customers">Khách hàng</a>
        <a class="tab <?= $tab === 'orders' ? 'active' : '' ?>" href="/admin/?tab=orders">Đơn hàng</a>
    </div>

    <?php if ($flash): ?>
        <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <div class="panel">
        <?php if ($tab === 'products'): ?>
            <div class="toolbar">
                <strong>Danh sách sản phẩm (<?= count($products) ?>)</strong>
                <a class="btn btn-primary" href="/admin/?tab=products&mode=new">+ Thêm sản phẩm</a>
            </div>

            <?php if ($mode === 'new' || ($mode === 'edit' && $editRecord)): ?>
                <form method="post">
                    <input type="hidden" name="entity" value="products">
                    <input type="hidden" name="op" value="<?= $mode === 'edit' ? 'update' : 'create' ?>">
                    <?php if ($mode === 'edit'): ?>
                        <input type="hidden" name="id" value="<?= (int)$editRecord['id'] ?>">
                    <?php endif; ?>
                    <div class="form-grid">
                        <div>
                            <label>Tên sản phẩm</label>
                            <input name="name" required value="<?= h($editRecord['name'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Giá</label>
                            <input name="price" type="number" step="0.01" min="0" required value="<?= h((string)($editRecord['price'] ?? 0)) ?>">
                        </div>
                        <div class="full">
                            <label>Mô tả</label>
                            <textarea name="description"><?= h($editRecord['description'] ?? '') ?></textarea>
                        </div>
                        <div>
                            <label>Số lượng còn lại</label>
                            <input name="stock_quantity" type="number" min="0" required value="<?= h((string)($editRecord['stock_quantity'] ?? 0)) ?>">
                        </div>
                    </div>
                    <button class="btn btn-primary" type="submit"><?= $mode === 'edit' ? 'Lưu cập nhật' : 'Thêm mới' ?></button>
                    <a class="btn btn-muted" href="/admin/?tab=products">Hủy</a>
                </form>
                <hr>
            <?php endif; ?>

            <table>
                <thead><tr><th>ID</th><th>Tên</th><th>Giá</th><th>Tồn kho</th><th>Mô tả</th><th>Thao tác</th></tr></thead>
                <tbody>
                <?php foreach ($products as $row): ?>
                    <tr>
                        <td><?= (int)$row['id'] ?></td>
                        <td><?= h($row['name']) ?></td>
                        <td><?= h((string)$row['price']) ?></td>
                        <td><?= (int)$row['stock_quantity'] ?></td>
                        <td><?= h($row['description']) ?></td>
                        <td class="actions">
                            <a class="btn btn-muted" href="/admin/?tab=products&mode=edit&id=<?= (int)$row['id'] ?>">Sửa</a>
                            <form class="inline" method="post" onsubmit="return confirm('Xóa sản phẩm này?')">
                                <input type="hidden" name="entity" value="products">
                                <input type="hidden" name="op" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                <button class="btn btn-danger" type="submit">Xóa</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif ($tab === 'customers'): ?>
            <div class="toolbar">
                <strong>Danh sách khách hàng (<?= count($customers) ?>)</strong>
                <a class="btn btn-primary" href="/admin/?tab=customers&mode=new">+ Thêm khách hàng</a>
            </div>

            <?php if ($mode === 'new' || ($mode === 'edit' && $editRecord)): ?>
                <form method="post">
                    <input type="hidden" name="entity" value="customers">
                    <input type="hidden" name="op" value="<?= $mode === 'edit' ? 'update' : 'create' ?>">
                    <?php if ($mode === 'edit'): ?>
                        <input type="hidden" name="id" value="<?= (int)$editRecord['id'] ?>">
                    <?php endif; ?>
                    <div class="form-grid">
                        <div>
                            <label>Tên khách hàng</label>
                            <input name="name" required value="<?= h($editRecord['name'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Số điện thoại</label>
                            <input name="phone" required value="<?= h($editRecord['phone'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Zalo</label>
                            <input name="zalo" value="<?= h($editRecord['zalo'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Email</label>
                            <input name="email" type="email" value="<?= h($editRecord['email'] ?? '') ?>">
                        </div>
                        <div>
                            <label>Ngày đăng ký (YYYY-MM-DD HH:MM:SS)</label>
                            <input name="registered_at" value="<?= h($editRecord['registered_at'] ?? date('Y-m-d H:i:s')) ?>">
                        </div>
                    </div>
                    <button class="btn btn-primary" type="submit"><?= $mode === 'edit' ? 'Lưu cập nhật' : 'Thêm mới' ?></button>
                    <a class="btn btn-muted" href="/admin/?tab=customers">Hủy</a>
                </form>
                <hr>
            <?php endif; ?>

            <table>
                <thead><tr><th>ID</th><th>Tên</th><th>Số điện thoại</th><th>Zalo</th><th>Email</th><th>Ngày đăng ký</th><th>Thao tác</th></tr></thead>
                <tbody>
                <?php foreach ($customers as $row): ?>
                    <tr>
                        <td><?= (int)$row['id'] ?></td>
                        <td><?= h($row['name']) ?></td>
                        <td><?= h($row['phone']) ?></td>
                        <td><?= h($row['zalo']) ?></td>
                        <td><?= h($row['email'] ?? '') ?></td>
                        <td><?= h($row['registered_at']) ?></td>
                        <td class="actions">
                            <a class="btn btn-muted" href="/admin/?tab=customers&mode=edit&id=<?= (int)$row['id'] ?>">Sửa</a>
                            <form class="inline" method="post" onsubmit="return confirm('Xóa khách hàng này?')">
                                <input type="hidden" name="entity" value="customers">
                                <input type="hidden" name="op" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                <button class="btn btn-danger" type="submit">Xóa</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="toolbar">
                <strong>Danh sách đơn hàng (<?= count($orders) ?>)</strong>
                <a class="btn btn-primary" href="/admin/?tab=orders&mode=new">+ Thêm đơn hàng</a>
            </div>
            <p class="muted">Khi thêm đơn hàng mới, hệ thống tự động trừ 1 đơn vị tồn kho của sản phẩm.</p>

            <?php if ($mode === 'new' || ($mode === 'edit' && $editRecord)): ?>
                <form method="post">
                    <input type="hidden" name="entity" value="orders">
                    <input type="hidden" name="op" value="<?= $mode === 'edit' ? 'update' : 'create' ?>">
                    <?php if ($mode === 'edit'): ?>
                        <input type="hidden" name="id" value="<?= (int)$editRecord['id'] ?>">
                    <?php endif; ?>
                    <div class="form-grid">
                        <div>
                            <label>Khách hàng</label>
                            <select name="customer_id" required>
                                <option value="">-- Chọn khách --</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>" <?= ((int)($editRecord['customer_id'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>>
                                        <?= h($c['name']) ?> - <?= h($c['phone']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Sản phẩm</label>
                            <select name="product_id" required>
                                <option value="">-- Chọn sản phẩm --</option>
                                <?php foreach ($products as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>" <?= ((int)($editRecord['product_id'] ?? 0) === (int)$p['id']) ? 'selected' : '' ?>>
                                        <?= h($p['name']) ?> (Tồn: <?= (int)$p['stock_quantity'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Số tiền</label>
                            <input name="amount" type="number" step="0.01" min="0" value="<?= h((string)($editRecord['amount'] ?? 0)) ?>">
                        </div>
                        <div>
                            <label>Trạng thái</label>
                            <?php $statusVal = (string)($editRecord['status'] ?? 'pending'); ?>
                            <select name="status">
                                <option value="pending" <?= $statusVal === 'pending' ? 'selected' : '' ?>>Chờ thanh toán</option>
                                <option value="success" <?= $statusVal === 'success' ? 'selected' : '' ?>>Đã thanh toán (cũ)</option>
                                <option value="paid" <?= $statusVal === 'paid' ? 'selected' : '' ?>>Đã thanh toán</option>
                                <option value="shipping" <?= $statusVal === 'shipping' ? 'selected' : '' ?>>Đang giao</option>
                                <option value="cancelled" <?= $statusVal === 'cancelled' ? 'selected' : '' ?>>Đã hủy</option>
                                <option value="failed" <?= $statusVal === 'failed' ? 'selected' : '' ?>>Thất bại</option>
                            </select>
                        </div>
                        <div class="full">
                            <label>Ngày mua (YYYY-MM-DD HH:MM:SS)</label>
                            <input name="purchased_at" value="<?= h($editRecord['purchased_at'] ?? date('Y-m-d H:i:s')) ?>">
                        </div>
                    </div>
                    <button class="btn btn-primary" type="submit"><?= $mode === 'edit' ? 'Lưu cập nhật' : 'Thêm mới' ?></button>
                    <a class="btn btn-muted" href="/admin/?tab=orders">Hủy</a>
                </form>
                <hr>
            <?php endif; ?>

            <table>
                <thead><tr><th>ID</th><th>Mã hóa đơn</th><th>Khách hàng</th><th>Sản phẩm</th><th>Số tiền</th><th>Trạng thái</th><th>Ngày mua</th><th>Thao tác</th></tr></thead>
                <tbody>
                <?php foreach ($orders as $row): ?>
                    <tr>
                        <td><?= (int)$row['id'] ?></td>
                        <td><?= h(($row['invoice_number'] ?? '') !== '' ? $row['invoice_number'] : 'Đang cập nhật') ?></td>
                        <td><?= h(($row['customer_name'] ?? '') . ' - ' . ($row['customer_phone'] ?? '')) ?></td>
                        <td><?= h($row['product_name']) ?></td>
                        <td><?= h((string)$row['amount']) ?></td>
                        <td><?= h(statusLabel((string)$row['status'])) ?></td>
                        <td><?= h($row['purchased_at']) ?></td>
                        <td class="actions">
                            <a class="btn btn-muted" href="/admin/?tab=orders&mode=edit&id=<?= (int)$row['id'] ?>">Sửa</a>
                            <form class="inline" method="post" onsubmit="return confirm('Xóa đơn hàng này?')">
                                <input type="hidden" name="entity" value="orders">
                                <input type="hidden" name="op" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                <button class="btn btn-danger" type="submit">Xóa</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
