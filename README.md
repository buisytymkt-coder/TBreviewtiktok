# ChamCham Website

Landing page + waitlist + admin + SePay checkout, chạy bằng PHP thuần và SQLite.

## 1) Yêu cầu hệ thống

- Linux VPS
- PHP 8.1+
- Extension PHP: `sqlite3`, `curl`, `json`, `openssl`, `mbstring`
- Web server: Apache hoặc Nginx
- Composer (nếu cần cài lại `vendor/`)

## 2) Chuẩn bị cấu hình

1. Copy file mẫu môi trường:
```bash
cp .env.example .env
```
2. Mở `.env` và điền đầy đủ các biến:
- `RESEND_API_KEY`
- `RESEND_FROM`
- `SEPAY_MERCHANT_ID`
- `SEPAY_MERCHANT_SECRET_KEY`
- `SEPAY_IPN_SECRET`
- `WAITLIST_WORKER_TOKEN`
- `WAITLIST_CHECKOUT_URL`
- `ADMIN_USER`, `ADMIN_PASS` (khuyến nghị để khóa `/admin`)

Lưu ý:
- Không commit `.env` lên git.
- `resend_config.txt` đã bị vô hiệu hóa, không dùng để lưu key nữa.

## 3) Deploy cơ bản

1. Upload source lên server.
2. Cài dependency (nếu chưa có `vendor/`):
```bash
composer install --no-dev --optimize-autoloader
```
3. Đảm bảo file DB `brain.db` tồn tại và web user có quyền ghi.
4. Trỏ web root đúng thư mục dự án.
5. Bật HTTPS.

## 4) Cron cho waitlist email

Tạo cron chạy mỗi 5 phút (đường dẫn PHP tùy host):
```bash
*/5 * * * * /usr/local/bin/php /path/to/project/waitlist_email_worker.php >> /path/to/logs/waitlist_worker.log 2>&1
```

Nếu dùng `WAITLIST_WORKER_TOKEN`, có thể đổi sang cron gọi HTTP kèm token hoặc giữ mode CLI và để token rỗng.

## 5) Kiểm tra nhanh sau deploy

- Submit form waitlist thành công.
- Email 1 gửi ngay.
- Email có `+test` gửi ngay 3 email.
- Tạo đơn trong `/admin` không lỗi.
- Callback SePay cập nhật trạng thái đơn.

## 6) Bảo mật tối thiểu

- Đã có `.htaccess` chặn truy cập file nhạy cảm (Apache).
- Không để file backup `.zip`, file log, DB trong thư mục public.
- Nên đặt thêm lớp đăng nhập cho `/admin` ở mức web server hoặc app.
