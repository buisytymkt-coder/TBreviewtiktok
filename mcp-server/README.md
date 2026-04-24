# ChamCham MCP Server

MCP server nay dung `stdio` de noi GoClaw voi he thong website ChamCham.

## Tools da co
- `get_daily_sales_snapshot`
- `create_checkout_order`
- `update_order_fulfillment_status`

## Chay local
```bash
cd mcp-server
npm install
node src/index.js
```

## Bien moi truong
- `BRAIN_DB_PATH` (optional): duong dan `brain.db` (mac dinh: `../brain.db`)
- `WEBSITE_BASE_URL` (optional): domain website (mac dinh: `https://shop.jocohome.shop`)
- `CHECKOUT_PATH` (optional): duong dan checkout (mac dinh: `/sepay_checkout.php`)

## Goi y cau hinh trong GoClaw (MCP server - stdio)
- Command: `node`
- Args: `["/opt/my-website/mcp-server/src/index.js"]`
- Env:
  - `BRAIN_DB_PATH=/opt/my-website/brain.db`
  - `WEBSITE_BASE_URL=https://shop.jocohome.shop`
  - `CHECKOUT_PATH=/sepay_checkout.php`
- Tool prefix de xuat: `cham_ops`
