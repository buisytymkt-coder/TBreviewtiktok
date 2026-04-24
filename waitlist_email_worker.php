<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib/waitlist_email_sequence.php';
require_once __DIR__ . '/lib/env.php';

function out(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$tokenRequired = trim(appEnv('WAITLIST_WORKER_TOKEN', ''));
$isCli = PHP_SAPI === 'cli';
if (!$isCli && $tokenRequired !== '') {
    $token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
    if (!hash_equals($tokenRequired, $token)) {
        out(401, ['success' => false, 'message' => 'Unauthorized token']);
    }
}

try {
    $db = new SQLite3(__DIR__ . '/brain.db');
    $db->enableExceptions(true);
    $db->busyTimeout(15000);
    $db->exec('PRAGMA journal_mode = WAL;');
    $db->exec('PRAGMA synchronous = NORMAL;');
    $db->exec('PRAGMA foreign_keys = ON;');

    waitlistEnsureEmailQueueTable($db);
    $result = waitlistProcessDueEmails($db, 50);

    out(200, [
        'success' => true,
        'message' => 'Processed waitlist email queue',
        'result' => $result,
    ]);
} catch (Throwable $e) {
    out(500, [
        'success' => false,
        'message' => 'Worker error: ' . $e->getMessage(),
    ]);
}
