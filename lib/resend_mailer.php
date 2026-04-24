<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

function resendGetApiKey(): string
{
    return trim(appEnv('RESEND_API_KEY', ''));
}

function resendGetFromAddress(): string
{
    $from = trim(appEnv('RESEND_FROM', ''));
    if ($from === '') {
        $from = 'Cham Cham <onboarding@resend.dev>';
    }
    return $from;
}

function resendSendEmailRaw(string $to, string $subject, string $html, string $text = ''): array
{
    $apiKey = resendGetApiKey();
    if ($apiKey === '') {
        return [
            'ok' => false,
            'status' => 0,
            'message' => 'Missing Resend API key',
            'id' => null,
            'delivered_to' => null,
            'fallback_used' => false,
        ];
    }

    $from = resendGetFromAddress();

    $payload = [
        'from' => $from,
        'to' => [$to],
        'subject' => $subject,
        'html' => $html,
    ];
    if ($text !== '') {
        $payload['text'] = $text;
    }

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 20,
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    if ($body === false) {
        return [
            'ok' => false,
            'status' => $status,
            'message' => $curlError !== '' ? $curlError : 'Unknown cURL error',
            'id' => null,
            'delivered_to' => null,
            'fallback_used' => false,
        ];
    }

    $decoded = json_decode($body, true);
    $id = is_array($decoded) ? ($decoded['id'] ?? null) : null;
    $message = is_array($decoded) ? ($decoded['message'] ?? '') : '';

    return [
        'ok' => $status >= 200 && $status < 300 && is_string($id) && $id !== '',
        'status' => $status,
        'message' => (string)$message,
        'id' => $id,
        'delivered_to' => $to,
        'fallback_used' => false,
    ];
}

function resendSendEmail(string $to, string $subject, string $html, string $text = ''): array
{
    $primary = resendSendEmailRaw($to, $subject, $html, $text);
    if ($primary['ok']) {
        return $primary;
    }

    $from = resendGetFromAddress();
    $msg = (string)($primary['message'] ?? '');
    $isOnboardingSender = stripos($from, 'onboarding@resend.dev') !== false;
    $isSandboxRestriction = (int)($primary['status'] ?? 0) === 403
        && stripos($msg, 'You can only send testing emails to your own email address') !== false;

    if (!$isOnboardingSender || !$isSandboxRestriction) {
        return $primary;
    }

    $fallbackTo = trim(appEnv('RESEND_TEST_TO', ''));
    if ($fallbackTo === '') {
        return $primary;
    }
    if (strcasecmp($to, $fallbackTo) === 0) {
        return $primary;
    }

    $fallback = resendSendEmailRaw($fallbackTo, $subject . ' [Sandbox Fallback]', $html, $text);
    $fallback['fallback_used'] = true;
    $fallback['original_to'] = $to;
    return $fallback;
}
