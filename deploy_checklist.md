# Deploy Checklist - ChamCham (Linux VPS)

Ngày cập nhật: 2026-04-14 (Asia/Ho_Chi_Minh)
Thư mục dự án: `/Users/buisyty/Chamcham`

## 1) Stack dự án

- [x] Backend: PHP thuần (không Laravel/Symfony)
- [x] Frontend: HTML/CSS/JavaScript
- [x] Database: SQLite (`brain.db`)
- [x] Dependency manager: Composer (`sepay/sepay-pg`)

## 2) File/cấu hình cần có để deploy

- [x] Tạo `README.md` hướng dẫn deploy cơ bản
- [x] Tạo `.env.example`
- [x] Tạo `.env` mẫu local (copy từ `.env.example`)
- [x] Tạo `.htaccess` chặn file nhạy cảm (Apache/cPanel)
- [x] Tạo `deploy/cron.example`
- [x] Tạo `.user.ini` mẫu cho logging/runtime

## 3) Bí mật / API key có lộ trong code không?

- [x] Gỡ hardcode SePay key khỏi `sepay_checkout.php`
- [x] Gỡ hardcode Telegram bot token khỏi `script.js`
- [x] Gỡ phụ thuộc key trong `resend_config.txt` (đã đổi sang cảnh báo deprecated)
- [x] Chuyển luồng đọc secret sang biến môi trường (`lib/env.php`)
- [x] Quét lại pattern secret trong source: không thấy key thật

## 4) Chuẩn bị trước deploy (chi tiết)

### A. Server runtime

- [ ] VPS Linux đã cài PHP 8.1+
- [ ] Đã bật extension `sqlite3`, `curl`, `json`, `openssl`, `mbstring`
- [ ] Web server (Apache hoặc Nginx + PHP-FPM) chạy ổn

### B. Source và dependency

- [ ] Upload source lên thư mục chạy thật trên VPS
- [ ] Chạy `composer install --no-dev --optimize-autoloader` trên VPS (nếu chưa có `vendor/`)
- [ ] Trỏ document root đúng thư mục dự án

### C. Database SQLite

- [ ] Upload `brain.db` lên VPS
- [ ] Cấp quyền ghi cho user chạy PHP vào thư mục chứa DB
- [ ] Thiết lập backup định kỳ cho `brain.db`

### D. Biến môi trường/secret

- [x] Đã chuẩn bị file `.env` mẫu
- [ ] Điền giá trị thật vào `.env` trên VPS:
  - `RESEND_API_KEY`
  - `RESEND_FROM`
  - `RESEND_TEST_TO` (nếu dùng sandbox fallback)
  - `SEPAY_MERCHANT_ID`
  - `SEPAY_MERCHANT_SECRET_KEY`
  - `SEPAY_IPN_SECRET`
  - `WAITLIST_WORKER_TOKEN`
  - `WAITLIST_CHECKOUT_URL`
  - `ADMIN_USER`
  - `ADMIN_PASS`
- [ ] Rotate toàn bộ key cũ đã từng lộ (Resend, SePay, Telegram)

### E. Cron cho email queue

- [x] Đã có file mẫu cron `deploy/cron.example`
- [x] Worker đã hỗ trợ chạy CLI không cần token (an toàn cho cron nội bộ)
- [ ] Cài cron thật trên VPS (mỗi 5 phút)

### F. Bảo mật web

- [x] Đã thêm `.htaccess` chặn `.env`, DB, zip/log
- [x] Đã thêm bảo vệ `/admin` bằng Basic Auth qua `ADMIN_USER`/`ADMIN_PASS`
- [ ] Bật HTTPS thật trên domain production
- [ ] Đặt `error_log` thật trong `.user.ini` (thay `USERNAME` bằng user host)
- [ ] Dọn file backup `.zip` khỏi web root production

### G. Smoke test sau deploy

- [ ] Trang chủ load đủ CSS/JS, không 404 asset
- [ ] Submit waitlist thường: tạo khách + gửi Email 1 ngay
- [ ] Submit waitlist email có `+test`: gửi ngay cả 3 email
- [ ] Chạy worker thủ công `php waitlist_email_worker.php` trả `success`
- [ ] Tạo đơn ở `/admin`: trừ tồn + gửi email xác nhận đơn
- [ ] Test callback SePay IPN: đơn chuyển `paid`

## Kết luận hiện tại

- [x] Phần codebase local đã được làm sạch secret và chuẩn hóa file deploy cơ bản.
- [ ] Phần hạ tầng production (VPS runtime, cron thật, SSL, key thật, smoke test production) cần thao tác trực tiếp trên máy chủ.
