<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib/resend_mailer.php';
require_once __DIR__ . '/lib/waitlist_email_sequence.php';

function jsonResponse(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cleanText(mixed $value): string
{
    return trim((string)$value);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonResponse(405, ['success' => false, 'message' => 'Method not allowed']);
}

$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    jsonResponse(400, ['success' => false, 'message' => 'Invalid JSON payload']);
}

$name = cleanText($payload['name'] ?? '');
$phone = cleanText($payload['phone'] ?? '');
$email = strtolower(cleanText($payload['email'] ?? ''));
$babyAge = cleanText($payload['babyAge'] ?? '');
$usage = cleanText($payload['usage'] ?? '');
$concern = cleanText($payload['concern'] ?? '');

if ($name === '' || $phone === '' || $email === '' || $babyAge === '' || $usage === '' || $concern === '') {
    jsonResponse(422, ['success' => false, 'message' => 'Thiếu dữ liệu bắt buộc']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(422, ['success' => false, 'message' => 'Email không hợp lệ']);
}

try {
    $db = new SQLite3(__DIR__ . '/brain.db');
    $db->enableExceptions(true);
    $db->busyTimeout(15000);
    $db->exec('PRAGMA journal_mode = WAL;');
    $db->exec('PRAGMA synchronous = NORMAL;');
    $db->exec('PRAGMA foreign_keys = ON;');

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

    $upsert = $db->prepare(
        'INSERT INTO customers(name, phone, zalo, email, registered_at)
         VALUES (:name, :phone, :zalo, :email, CURRENT_TIMESTAMP)
         ON CONFLICT(phone) DO UPDATE SET
            name = excluded.name,
            email = excluded.email,
            updated_at = CURRENT_TIMESTAMP'
    );
    $upsert->bindValue(':name', $name, SQLITE3_TEXT);
    $upsert->bindValue(':phone', $phone, SQLITE3_TEXT);
    $upsert->bindValue(':zalo', $phone, SQLITE3_TEXT);
    $upsert->bindValue(':email', $email, SQLITE3_TEXT);
    $upsert->execute();

    $findStmt = $db->prepare('SELECT id FROM customers WHERE phone = :phone LIMIT 1');
    $findStmt->bindValue(':phone', $phone, SQLITE3_TEXT);
    $res = $findStmt->execute();
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
    if (!$row) {
        throw new RuntimeException('Không tìm thấy khách hàng sau khi upsert.');
    }
    $customerId = (int)$row['id'];
    waitlistEnsureEmailQueueTable($db);

    $isTestMode = stripos($email, '+test') !== false;
    $deliveryEmail = $email;
    if ($isTestMode && str_ends_with($email, '@gmail.com')) {
        [$localPart, $domainPart] = explode('@', $email, 2);
        if (stripos($localPart, '+test') !== false) {
            $normalizedLocal = str_ireplace('+test', '', $localPart);
            $deliveryEmail = $normalizedLocal . '@' . $domainPart;
        }
    }
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh'));

    $step1At = $now;
    $step2At = $isTestMode ? $now : $now->modify('+2 days');
    $step3At = $isTestMode ? $now : $now->modify('+3 days');

    $db->exec('BEGIN IMMEDIATE TRANSACTION');
    waitlistQueueEmail($db, $customerId, $deliveryEmail, $name, 1, $step1At->format('Y-m-d H:i:s'));
    waitlistQueueEmail($db, $customerId, $deliveryEmail, $name, 2, $step2At->format('Y-m-d H:i:s'));
    waitlistQueueEmail($db, $customerId, $deliveryEmail, $name, 3, $step3At->format('Y-m-d H:i:s'));
    $db->exec('COMMIT');

    // Run sender now so step 1 is immediate. In +test mode, all 3 are sent now.
    $processResult = waitlistProcessDueEmails($db, $isTestMode ? 10 : 3);

    jsonResponse(200, [
        'success' => true,
        'message' => $isTestMode
            ? 'Đã xếp lịch và gửi ngay 3 email test.'
            : 'Đã xếp lịch email: gửi ngay + 2 ngày + 3 ngày.',
        'customer_id' => $customerId,
        'test_mode' => $isTestMode,
        'delivery_email' => $deliveryEmail,
        'queue_process' => $processResult,
    ]);
} catch (Throwable $e) {
    jsonResponse(500, ['success' => false, 'message' => 'Lỗi DB/email queue: ' . $e->getMessage()]);
}
