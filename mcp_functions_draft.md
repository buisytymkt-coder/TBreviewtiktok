# MCP Functions Draft (Telegram-first)

## 1) `get_daily_sales_snapshot`
- Input params:
  - `date` (string, `YYYY-MM-DD`, optional; mặc định hôm nay)
  - `timezone` (string, optional; mặc định `Asia/Ho_Chi_Minh`)
- Output dự kiến:
  - Tổng số đơn trong ngày
  - Doanh thu theo trạng thái (`pending`, `paid`, `shipping`, `cancelled`, `failed`)
  - Số đơn mới từ SePay checkout
  - Top sản phẩm bán trong ngày
  - Danh sách 5 đơn mới nhất (invoice, khách, số tiền, trạng thái)
- Tình huống dùng hàng ngày: Mỗi sáng mở Telegram là biết hôm nay đã có bao nhiêu đơn và doanh thu thực nhận đến đâu.
- Ví dụ câu nhắn Telegram sẽ trigger function này: `Cho tôi báo cáo doanh thu hôm nay`
- Độ ưu tiên: **5**

## 2) `create_checkout_order`
- Input params:
  - `customer_name` (string)
  - `customer_contact` (string; phone hoặc email)
  - `customer_email` (string, optional)
  - `item_name` (string)
  - `amount` (number, VND)
  - `note` (string, optional)
- Output dự kiến:
  - `order_id`, `invoice_number`
  - `checkout_url` (link SePay)
  - `embed_html` hoặc `payment_form_fields` (nếu cần)
  - Trạng thái khởi tạo mặc định `pending`
- Tình huống dùng hàng ngày: Có khách nhắn Telegram muốn mua, bạn tạo đơn và gửi link thanh toán ngay trong 10 giây.
- Ví dụ câu nhắn Telegram sẽ trigger function này: `Tạo đơn mới cho Nguyễn Lan, sđt 0909123456, gói Combo 2 chai 50ml, giá 350000`
- Độ ưu tiên: **5**

## 3) `update_order_fulfillment_status`
- Input params:
  - `order_id` (number, optional)
  - `invoice_number` (string, optional)
  - `status` (enum string: `pending|paid|shipping|cancelled|failed`)
  - `payment_note` (string, optional)
- Output dự kiến:
  - Đơn hàng trước/sau khi cập nhật
  - Thời gian cập nhật
  - Cảnh báo nếu trạng thái chuyển không hợp lệ
- Tình huống dùng hàng ngày: Khi đã đóng gói xong, bạn đổi nhanh từ `Đã thanh toán` sang `Đang giao` ngay trên Telegram.
- Ví dụ câu nhắn Telegram sẽ trigger function này: `Cập nhật đơn CC-20260412175306-404 sang trạng thái shipping`
- Độ ưu tiên: **4**
