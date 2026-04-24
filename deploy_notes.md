# Deploy Notes (VPS Ubuntu Production)

## 1) Stack hiện tại

- Ngôn ngữ/chạy thực tế: **PHP thuần** (không dùng framework Node/Python).
- Frontend: HTML/CSS/JS.
- Backend endpoint:
  - `/admin` (PHP + SQLite)
  - `/thanh-toan` + `sepay_checkout.php` + `sepay_ipn.php`
  - `waitlist_submit.php`, `waitlist_email_worker.php`
- Database: **SQLite** file `brain.db`.

Vì không phải website HTML tĩnh thuần, **không cần wrap Express**.

## 2) Biến môi trường bắt buộc trên VPS (.env)

Tạo file `.env` từ `.env.example` và set các biến sau:

- `TZ=Asia/Ho_Chi_Minh`
- `RESEND_API_KEY=...`
- `RESEND_FROM=Cham Cham <your-verified-domain-email>`
- `RESEND_TEST_TO=` (optional)
- `SEPAY_MERCHANT_ID=...`
- `SEPAY_MERCHANT_SECRET_KEY=...`
- `SEPAY_ENV=production`
- `SEPAY_IPN_SECRET=...`
- `WAITLIST_CHECKOUT_URL=https://your-domain.com/sepay_checkout.php`
- `WAITLIST_WORKER_TOKEN=...`
- `ADMIN_USER=...`
- `ADMIN_PASS=...`

## 3) Lệnh chạy server

### Khuyến nghị production

- Dùng **Nginx/Apache + PHP-FPM** (port public thường 80/443).

### Chạy nhanh bằng PHP built-in (cho test/staging)

```bash
PORT=${PORT:-3000}
php -S 0.0.0.0:${PORT}
```

- App sẽ lắng nghe tại: `PORT` từ môi trường, mặc định `3000`.

## 4) Cron worker (email waitlist)

```bash
*/5 * * * * /usr/bin/php /path/to/project/waitlist_email_worker.php >> /path/to/project/logs/waitlist_worker.log 2>&1
```

## 5) Ghi chú bảo mật

- Không commit `.env`, `brain.db`, file backup `.zip`, logs.
- Phân quyền ghi cho web user đối với `brain.db` và thư mục `logs/`.
- Luôn bật HTTPS trước khi mở public.
