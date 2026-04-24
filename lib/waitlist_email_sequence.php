<?php

declare(strict_types=1);

require_once __DIR__ . '/resend_mailer.php';
require_once __DIR__ . '/env.php';

function waitlistEnsureEmailQueueTable(SQLite3 $db): void
{
    $db->exec(
        'CREATE TABLE IF NOT EXISTS waitlist_email_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INTEGER NOT NULL,
            customer_email TEXT NOT NULL,
            customer_name TEXT NOT NULL,
            step INTEGER NOT NULL,
            subject TEXT NOT NULL,
            body_text TEXT NOT NULL,
            body_html TEXT NOT NULL,
            scheduled_at TEXT NOT NULL,
            sent_at TEXT,
            resend_id TEXT,
            attempts INTEGER NOT NULL DEFAULT 0,
            last_error TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE CASCADE
        )'
    );
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_waitlist_email_unique ON waitlist_email_queue(customer_id, step)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_waitlist_email_due ON waitlist_email_queue(sent_at, scheduled_at)');
}

function waitlistCheckoutLink(): string
{
    $link = trim(appEnv('WAITLIST_CHECKOUT_URL', ''));
    if ($link !== '') {
        return $link;
    }
    return 'https://tinhdautramchamcham.com/sepay_checkout.php';
}

function waitlistEmailTemplate(int $step, string $customerName): array
{
    $safeName = trim($customerName) !== '' ? trim($customerName) : 'chị em';
    $checkoutLink = waitlistCheckoutLink();

    if ($step === 1) {
        $subject = 'Chào chị em, Trâm đây 🌿';
        $text = "Chị em ơi,\n\n"
            . "Cảm ơn chị em đã để lại thông tin nha.\n"
            . "Trâm là người đồng hành cùng các mẹ trong hành trình chọn sản phẩm thiên nhiên an toàn cho mẹ và bé.\n\n"
            . "Nói thật là tụi mình không chạy theo lời quảng cáo cho kêu.\n"
            . "Trâm chỉ muốn gửi cho chị em những gì thật sự dễ dùng, dễ hiểu, dùng được trong đời sống hằng ngày.\n\n"
            . "Chị em cứ yên tâm ở lại danh sách chờ.\n"
            . "Khi có đợt mở mới hoặc ưu tiên tốt, Trâm báo trước cho mình liền.\n\n"
            . "Thương,\nTrâm";
    } elseif ($step === 2) {
        $subject = '1 insight nhỏ nhưng rất quan trọng khi chọn tinh dầu cho mẹ và bé';
        $text = "Chị em nhé,\n\n"
            . "Một insight Trâm thấy nhiều mẹ bỏ qua:\n"
            . "Không phải “mùi thơm dễ chịu” là tiêu chí quan trọng nhất.\n\n"
            . "Điều cần ưu tiên trước là:\n"
            . "- Thành phần rõ ràng, minh bạch\n"
            . "- Cách dùng cụ thể theo từng tình huống\n"
            . "- Dùng lượng vừa đủ, không lạm dụng\n\n"
            . "Vì với mẹ và bé, an toàn luôn đi trước cảm giác “thơm” hay “đỡ liền”.\n"
            . "Chậm một chút mà đúng thì đi đường dài nhẹ đầu hơn nhiều.\n\n"
            . "Vậy đó, Trâm gửi để chị em có thêm góc nhìn khi chọn sản phẩm cho gia đình.\n\n"
            . "Thương,\nTrâm";
    } else {
        $subject = 'Mở đơn hôm nay: Tràm Chăm Chăm đã sẵn sàng cho chị em 🌿';
        $text = "Chị em ơi,\n\n"
            . "Trâm mở đơn hôm nay nha.\n"
            . "Hiện có 3 lựa chọn để chị em chọn theo nhu cầu:\n"
            . "- Tinh Dầu Tràm Huế Chăm Chăm 50ml — 89.000đ\n"
            . "  Dùng cho mẹ và bé, giữ ấm và xông phòng.\n"
            . "- Combo 2+1 Tràm Chăm Chăm — 178.000đ\n"
            . "  Phù hợp gia đình dùng dài hạn, tiết kiệm hơn.\n"
            . "- Tinh Dầu Tràm Chăm Chăm 10ml (Mini) — 39.000đ\n"
            . "  Bản nhỏ gọn, tiện mang theo khi ra ngoài.\n\n"
            . "Nói thật là điểm Trâm giữ kỹ nhất vẫn là sự minh bạch và tính dễ dùng mỗi ngày.\n"
            . "Chị em cần bản nào thì bấm thanh toán trực tiếp ở link này nha:\n\n"
            . $checkoutLink . "\n\n"
            . "Nếu cần Trâm gợi ý bản phù hợp theo nhu cầu nhà mình, chị em cứ reply email này, Trâm trả lời liền.\n\n"
            . "Thương,\nTrâm";
    }

    $html = '<p>Chào ' . htmlspecialchars($safeName, ENT_QUOTES, 'UTF-8') . ',</p>'
        . '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</p>';

    return [
        'subject' => $subject,
        'text' => $text,
        'html' => $html,
    ];
}

function waitlistQueueEmail(
    SQLite3 $db,
    int $customerId,
    string $customerEmail,
    string $customerName,
    int $step,
    string $scheduledAt
): void {
    $template = waitlistEmailTemplate($step, $customerName);

    $stmt = $db->prepare(
        'INSERT INTO waitlist_email_queue(
            customer_id, customer_email, customer_name, step, subject, body_text, body_html, scheduled_at
         ) VALUES (
            :customer_id, :customer_email, :customer_name, :step, :subject, :body_text, :body_html, :scheduled_at
         )
         ON CONFLICT(customer_id, step) DO UPDATE SET
            customer_email = excluded.customer_email,
            customer_name = excluded.customer_name,
            subject = excluded.subject,
            body_text = excluded.body_text,
            body_html = excluded.body_html,
            scheduled_at = excluded.scheduled_at,
            sent_at = NULL,
            resend_id = NULL,
            attempts = 0,
            last_error = NULL,
            updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->bindValue(':customer_id', $customerId, SQLITE3_INTEGER);
    $stmt->bindValue(':customer_email', $customerEmail, SQLITE3_TEXT);
    $stmt->bindValue(':customer_name', $customerName, SQLITE3_TEXT);
    $stmt->bindValue(':step', $step, SQLITE3_INTEGER);
    $stmt->bindValue(':subject', $template['subject'], SQLITE3_TEXT);
    $stmt->bindValue(':body_text', $template['text'], SQLITE3_TEXT);
    $stmt->bindValue(':body_html', $template['html'], SQLITE3_TEXT);
    $stmt->bindValue(':scheduled_at', $scheduledAt, SQLITE3_TEXT);
    $stmt->execute();
}

function waitlistProcessDueEmails(SQLite3 $db, int $limit = 20): array
{
    $result = ['sent' => 0, 'failed' => 0, 'processed_ids' => []];
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d H:i:s');

    $stmt = $db->prepare(
        'SELECT *
         FROM waitlist_email_queue
         WHERE sent_at IS NULL
           AND scheduled_at <= :now
         ORDER BY scheduled_at ASC, id ASC
         LIMIT :limit'
    );
    $stmt->bindValue(':now', $now, SQLITE3_TEXT);
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $res = $stmt->execute();

    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $queueId = (int)$row['id'];
        $send = resendSendEmail(
            (string)$row['customer_email'],
            (string)$row['subject'],
            (string)$row['body_html'],
            (string)$row['body_text']
        );

        $update = $db->prepare(
            'UPDATE waitlist_email_queue
             SET attempts = attempts + 1,
                 sent_at = CASE WHEN :ok = 1 THEN CURRENT_TIMESTAMP ELSE sent_at END,
                 resend_id = CASE WHEN :ok = 1 THEN :resend_id ELSE resend_id END,
                 last_error = CASE WHEN :ok = 1 THEN NULL ELSE :last_error END,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $ok = $send['ok'] ? 1 : 0;
        $update->bindValue(':ok', $ok, SQLITE3_INTEGER);
        $update->bindValue(':resend_id', $send['id'] ?? null, ($send['id'] ?? null) === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $update->bindValue(':last_error', $send['message'] ?? null, ($send['message'] ?? null) === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $update->bindValue(':id', $queueId, SQLITE3_INTEGER);
        $update->execute();

        $result['processed_ids'][] = $queueId;
        if ($send['ok']) {
            $result['sent']++;
        } else {
            $result['failed']++;
        }
    }

    return $result;
}
