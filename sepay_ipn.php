<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/lib/env.php';

$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON payload']);
    exit;
}

// Neu ban da set Auth Type = Secret Key tren SePay, dat key nay vao ENV.
// Vi du tren hosting: SEPAY_IPN_SECRET=ipn_chamcham_2026_xxx
$expectedIpnSecret = appEnv('SEPAY_IPN_SECRET', '');
if ($expectedIpnSecret !== '') {
    $receivedSecret = $_SERVER['HTTP_X_SEPAY_SECRET'] ?? $_SERVER['HTTP_X_SECRET_KEY'] ?? '';
    if (!hash_equals($expectedIpnSecret, (string)$receivedSecret)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized IPN secret']);
        exit;
    }
}

$invoiceNumber = trim((string)($payload['order']['order_invoice_number'] ?? ''));
$notificationType = trim((string)($payload['notification_type'] ?? ''));
$orderStatus = trim((string)($payload['order']['order_status'] ?? ''));
$transactionStatus = trim((string)($payload['transaction']['transaction_status'] ?? ''));
$gatewayOrderId = trim((string)($payload['order']['order_id'] ?? ''));
$gatewayTransactionId = trim((string)($payload['transaction']['transaction_id'] ?? ''));
$orderAmount = (float)($payload['order']['order_amount'] ?? 0);

if ($invoiceNumber === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing order_invoice_number']);
    exit;
}

$db = new SQLite3(__DIR__ . '/brain.db');
$db->enableExceptions(true);
$db->busyTimeout(15000);
$db->exec('PRAGMA journal_mode = WAL;');
$db->exec('PRAGMA synchronous = NORMAL;');
$db->exec('PRAGMA foreign_keys = ON;');

$newStatus = null;
if (
    $notificationType === 'ORDER_PAID' ||
    $orderStatus === 'CAPTURED' ||
    $transactionStatus === 'APPROVED'
) {
    $newStatus = 'paid';
} elseif (
    $notificationType === 'ORDER_CANCELLED' ||
    $orderStatus === 'CANCELLED' ||
    $transactionStatus === 'DECLINED'
) {
    $newStatus = 'cancelled';
}

if ($newStatus === null) {
    // Nhan duoc IPN nhung chua thuoc trang thai can update.
    echo json_encode(['success' => true, 'message' => 'No status change']);
    exit;
}

$db->exec('BEGIN IMMEDIATE TRANSACTION');
try {
    $stmt = $db->prepare(
        'UPDATE orders
         SET status = :status,
             gateway_order_id = :gateway_order_id,
             gateway_transaction_id = :gateway_transaction_id,
             amount = CASE WHEN :amount > 0 THEN :amount ELSE amount END,
             paid_at = CASE WHEN :status = \'paid\' THEN CURRENT_TIMESTAMP ELSE paid_at END,
             updated_at = CURRENT_TIMESTAMP
         WHERE invoice_number = :invoice_number'
    );
    $stmt->bindValue(':status', $newStatus, SQLITE3_TEXT);
    $stmt->bindValue(':gateway_order_id', $gatewayOrderId === '' ? null : $gatewayOrderId, $gatewayOrderId === '' ? SQLITE3_NULL : SQLITE3_TEXT);
    $stmt->bindValue(':gateway_transaction_id', $gatewayTransactionId === '' ? null : $gatewayTransactionId, $gatewayTransactionId === '' ? SQLITE3_NULL : SQLITE3_TEXT);
    $stmt->bindValue(':amount', $orderAmount, SQLITE3_FLOAT);
    $stmt->bindValue(':invoice_number', $invoiceNumber, SQLITE3_TEXT);
    $stmt->execute();

    if ($db->changes() === 0) {
        throw new RuntimeException('Order not found for invoice ' . $invoiceNumber);
    }

    $db->exec('COMMIT');
    echo json_encode(['success' => true, 'message' => 'Order updated', 'invoice' => $invoiceNumber, 'status' => $newStatus]);
} catch (Throwable $e) {
    $db->exec('ROLLBACK');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
