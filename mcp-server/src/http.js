import { spawnSync } from "node:child_process";
import path from "node:path";
import { fileURLToPath } from "node:url";
import * as z from "zod/v4";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StreamableHTTPServerTransport } from "@modelcontextprotocol/sdk/server/streamableHttp.js";
import { createMcpExpressApp } from "@modelcontextprotocol/sdk/server/express.js";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const PROJECT_ROOT = path.resolve(__dirname, "..", "..");
const DB_PATH = process.env.BRAIN_DB_PATH || path.join(PROJECT_ROOT, "brain.db");
const WEBSITE_BASE_URL = (process.env.WEBSITE_BASE_URL || "https://shop.jocohome.shop").replace(/\/+$/, "");
const CHECKOUT_PATH = process.env.CHECKOUT_PATH || "/sepay_checkout.php";
const PORT = Number(process.env.MCP_HTTP_PORT || 3010);

function sqlQuote(value) {
  return `'${String(value).replace(/'/g, "''")}'`;
}

function runSql(sql, { json = false } = {}) {
  const args = [];
  if (json) args.push("-json");
  args.push(DB_PATH, sql);

  const result = spawnSync("sqlite3", args, {
    encoding: "utf8",
    maxBuffer: 1024 * 1024 * 10,
  });

  if (result.error) throw new Error(`sqlite3 error: ${result.error.message}`);
  if (result.status !== 0) {
    throw new Error(`sqlite3 failed: ${(result.stderr || "").trim() || "unknown error"}`);
  }

  const stdout = (result.stdout || "").trim();
  if (!json) return stdout;
  if (!stdout) return [];
  return JSON.parse(stdout);
}

function statusLabel(status) {
  switch (String(status || "").toLowerCase()) {
    case "pending":
      return "Chờ thanh toán";
    case "paid":
    case "success":
      return "Đã thanh toán";
    case "shipping":
      return "Đang giao";
    case "cancelled":
      return "Đã hủy";
    case "failed":
      return "Thất bại";
    default:
      return status || "";
  }
}

function todayYmd() {
  const now = new Date();
  const y = now.getFullYear();
  const m = String(now.getMonth() + 1).padStart(2, "0");
  const d = String(now.getDate()).padStart(2, "0");
  return `${y}-${m}-${d}`;
}

function buildCheckoutUrl(invoiceNumber) {
  const params = new URLSearchParams({ invoice: invoiceNumber, resume: "1" });
  return `${WEBSITE_BASE_URL}${CHECKOUT_PATH}?${params.toString()}`;
}

function createInvoiceNumber() {
  const now = new Date();
  const y = now.getFullYear();
  const m = String(now.getMonth() + 1).padStart(2, "0");
  const d = String(now.getDate()).padStart(2, "0");
  const hh = String(now.getHours()).padStart(2, "0");
  const mm = String(now.getMinutes()).padStart(2, "0");
  const ss = String(now.getSeconds()).padStart(2, "0");
  const rand = Math.floor(100 + Math.random() * 900);
  return `CC-${y}${m}${d}${hh}${mm}${ss}-${rand}`;
}

function getDailySalesSnapshot({ date, timezone }) {
  const dateVal = String(date || todayYmd()).trim();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(dateVal)) {
    throw new Error("date phải theo định dạng YYYY-MM-DD");
  }

  const totals = runSql(
    `
      SELECT
        COUNT(*) AS total_orders,
        COALESCE(SUM(amount), 0) AS total_revenue,
        COALESCE(SUM(CASE WHEN status IN ('paid','success') THEN amount ELSE 0 END), 0) AS paid_revenue
      FROM orders
      WHERE DATE(purchased_at) = ${sqlQuote(dateVal)};
    `,
    { json: true }
  )[0] || { total_orders: 0, total_revenue: 0, paid_revenue: 0 };

  const byStatus = runSql(
    `
      SELECT status, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS revenue
      FROM orders
      WHERE DATE(purchased_at) = ${sqlQuote(dateVal)}
      GROUP BY status
      ORDER BY count DESC;
    `,
    { json: true }
  ).map((row) => ({
    status: row.status,
    status_label: statusLabel(row.status),
    count: Number(row.count || 0),
    revenue: Number(row.revenue || 0),
  }));

  const topProducts = runSql(
    `
      SELECT COALESCE(product_name, 'Khác') AS product_name,
             COUNT(*) AS sold_count,
             COALESCE(SUM(amount), 0) AS revenue
      FROM orders
      WHERE DATE(purchased_at) = ${sqlQuote(dateVal)}
      GROUP BY COALESCE(product_name, 'Khác')
      ORDER BY sold_count DESC, revenue DESC
      LIMIT 5;
    `,
    { json: true }
  ).map((row) => ({
    product_name: row.product_name,
    sold_count: Number(row.sold_count || 0),
    revenue: Number(row.revenue || 0),
  }));

  const latestOrders = runSql(
    `
      SELECT o.id, COALESCE(o.invoice_number, '') AS invoice_number,
             COALESCE(c.name, '') AS customer_name,
             o.product_name, o.amount, o.status, o.purchased_at
      FROM orders o
      LEFT JOIN customers c ON c.id = o.customer_id
      WHERE DATE(o.purchased_at) = ${sqlQuote(dateVal)}
      ORDER BY o.id DESC
      LIMIT 5;
    `,
    { json: true }
  ).map((row) => ({ ...row, status_label: statusLabel(row.status) }));

  return {
    date: dateVal,
    timezone: String(timezone || "Asia/Ho_Chi_Minh"),
    total_orders: Number(totals.total_orders || 0),
    total_revenue: Number(totals.total_revenue || 0),
    paid_revenue: Number(totals.paid_revenue || 0),
    by_status: byStatus,
    top_products: topProducts,
    latest_orders: latestOrders,
  };
}

function createCheckoutOrder({ customer_name, customer_contact, customer_email, item_name, amount, note }) {
  const customerName = String(customer_name || "").trim();
  const customerContact = String(customer_contact || "").trim();
  const customerEmail = String(customer_email || "").trim();
  const itemName = String(item_name || "").trim();
  const amountVal = Number(amount || 0);
  const noteVal = String(note || "").trim();

  if (!customerName || !customerContact || !itemName) {
    throw new Error("Thiếu dữ liệu bắt buộc: customer_name, customer_contact, item_name");
  }
  if (!Number.isFinite(amountVal) || amountVal < 2000) {
    throw new Error("amount phải là số hợp lệ và >= 2000");
  }

  const invoiceNumber = createInvoiceNumber();
  const email = customerEmail || (customerContact.includes("@") ? customerContact : "");
  const escapedPhone = sqlQuote(customerContact);
  const escapedEmail = email ? sqlQuote(email.toLowerCase()) : "NULL";

  const rows = runSql(
    `
      BEGIN IMMEDIATE TRANSACTION;
      INSERT INTO customers(name, phone, zalo, email, registered_at, created_at, updated_at)
      SELECT
        ${sqlQuote(customerName)},
        ${escapedPhone},
        CASE WHEN instr(${escapedPhone}, '@') > 0 THEN NULL ELSE ${escapedPhone} END,
        ${escapedEmail},
        CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
      WHERE NOT EXISTS (
        SELECT 1 FROM customers
        WHERE phone = ${escapedPhone}
           OR (${escapedEmail} IS NOT NULL AND lower(email) = lower(${escapedEmail}))
      );

      UPDATE customers
      SET name = ${sqlQuote(customerName)},
          email = CASE WHEN ${escapedEmail} IS NOT NULL THEN ${escapedEmail} ELSE email END,
          updated_at = CURRENT_TIMESTAMP
      WHERE phone = ${escapedPhone}
         OR (${escapedEmail} IS NOT NULL AND lower(email) = lower(${escapedEmail}));

      INSERT INTO orders(customer_id, product_id, product_name, amount, status, purchased_at, created_at, updated_at, invoice_number, payment_note)
      VALUES (
        (
          SELECT id FROM customers
          WHERE phone = ${escapedPhone}
             OR (${escapedEmail} IS NOT NULL AND lower(email) = lower(${escapedEmail}))
          ORDER BY id DESC LIMIT 1
        ),
        NULL,
        ${sqlQuote(itemName)},
        ${Math.round(amountVal)},
        'pending',
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP,
        CURRENT_TIMESTAMP,
        ${sqlQuote(invoiceNumber)},
        ${sqlQuote(noteVal || "Tạo từ MCP")}
      );

      SELECT o.id AS order_id, o.invoice_number, o.status, o.amount,
             c.id AS customer_id, c.name AS customer_name, c.phone AS customer_contact, c.email AS customer_email
      FROM orders o
      LEFT JOIN customers c ON c.id = o.customer_id
      WHERE o.invoice_number = ${sqlQuote(invoiceNumber)}
      LIMIT 1;
      COMMIT;
    `,
    { json: true }
  );

  if (!rows.length) throw new Error("Không tạo được đơn hàng");
  const order = rows[0];
  return {
    order_id: Number(order.order_id),
    invoice_number: String(order.invoice_number),
    status: String(order.status),
    status_label: statusLabel(order.status),
    amount: Number(order.amount),
    customer: {
      customer_id: Number(order.customer_id),
      customer_name: String(order.customer_name || ""),
      customer_contact: String(order.customer_contact || ""),
      customer_email: String(order.customer_email || ""),
    },
    checkout_url: buildCheckoutUrl(String(order.invoice_number)),
    admin_order_url: `${WEBSITE_BASE_URL}/admin/?tab=orders`,
  };
}

function updateOrderFulfillmentStatus({ order_id, invoice_number, status, payment_note }) {
  const orderId = Number(order_id || 0);
  const invoiceNumber = String(invoice_number || "").trim();
  const statusVal = String(status || "").trim().toLowerCase();
  const paymentNote = String(payment_note || "").trim();
  if (!orderId && !invoiceNumber) throw new Error("Cần truyền order_id hoặc invoice_number");
  if (!["pending", "paid", "shipping", "cancelled", "failed", "success"].includes(statusVal)) {
    throw new Error("status không hợp lệ");
  }

  const whereClause = orderId ? `id = ${orderId}` : `invoice_number = ${sqlQuote(invoiceNumber)}`;
  const before = runSql(
    `SELECT id, invoice_number, status, amount, product_name, purchased_at, payment_note
     FROM orders WHERE ${whereClause} LIMIT 1;`,
    { json: true }
  )[0];
  if (!before) throw new Error("Không tìm thấy đơn hàng cần cập nhật");

  const after = runSql(
    `
      UPDATE orders
      SET status = ${sqlQuote(statusVal)},
          payment_note = CASE WHEN ${sqlQuote(paymentNote)} <> '' THEN ${sqlQuote(paymentNote)} ELSE payment_note END,
          paid_at = CASE WHEN ${sqlQuote(statusVal)} IN ('paid','success') THEN COALESCE(paid_at, CURRENT_TIMESTAMP) ELSE paid_at END,
          updated_at = CURRENT_TIMESTAMP
      WHERE ${whereClause};

      SELECT id, invoice_number, status, amount, product_name, purchased_at, payment_note, paid_at, updated_at
      FROM orders WHERE ${whereClause} LIMIT 1;
    `,
    { json: true }
  )[0];

  return {
    before: { ...before, status_label: statusLabel(before.status) },
    after: { ...after, status_label: statusLabel(after.status) },
  };
}

function getServer() {
  const server = new McpServer({
    name: "chamcham-ops-http",
    version: "1.0.0",
  });

  server.registerTool(
    "get_daily_sales_snapshot",
    {
      description: "Lấy snapshot doanh thu/ngày bán theo trạng thái đơn hàng và top sản phẩm.",
      inputSchema: {
        date: z.string().optional().describe("YYYY-MM-DD, mặc định hôm nay"),
        timezone: z.string().optional().describe("Mặc định Asia/Ho_Chi_Minh"),
      },
    },
    async (args) => ({
      content: [{ type: "text", text: JSON.stringify(getDailySalesSnapshot(args), null, 2) }],
    })
  );

  server.registerTool(
    "create_checkout_order",
    {
      description: "Tạo đơn pending trong brain.db và trả checkout_url để khách thanh toán SePay.",
      inputSchema: {
        customer_name: z.string(),
        customer_contact: z.string(),
        customer_email: z.string().optional(),
        item_name: z.string(),
        amount: z.number(),
        note: z.string().optional(),
      },
    },
    async (args) => ({
      content: [{ type: "text", text: JSON.stringify(createCheckoutOrder(args), null, 2) }],
    })
  );

  server.registerTool(
    "update_order_fulfillment_status",
    {
      description: "Cập nhật trạng thái vận hành đơn hàng.",
      inputSchema: {
        order_id: z.number().optional(),
        invoice_number: z.string().optional(),
        status: z.enum(["pending", "paid", "shipping", "cancelled", "failed", "success"]),
        payment_note: z.string().optional(),
      },
    },
    async (args) => ({
      content: [{ type: "text", text: JSON.stringify(updateOrderFulfillmentStatus(args), null, 2) }],
    })
  );

  return server;
}

const app = createMcpExpressApp();
app.post("/mcp", async (req, res) => {
  const server = getServer();
  try {
    const transport = new StreamableHTTPServerTransport({
      sessionIdGenerator: undefined,
    });
    await server.connect(transport);
    await transport.handleRequest(req, res, req.body);
    res.on("close", () => {
      transport.close();
      server.close();
    });
  } catch (error) {
    if (!res.headersSent) {
      res.status(500).json({
        jsonrpc: "2.0",
        error: { code: -32603, message: error instanceof Error ? error.message : "Internal server error" },
        id: null,
      });
    }
  }
});

app.get("/mcp", async (_req, res) => {
  res.status(405).json({
    jsonrpc: "2.0",
    error: { code: -32000, message: "Method not allowed." },
    id: null,
  });
});

app.delete("/mcp", async (_req, res) => {
  res.status(405).json({
    jsonrpc: "2.0",
    error: { code: -32000, message: "Method not allowed." },
    id: null,
  });
});

app.listen(PORT, (error) => {
  if (error) {
    console.error("Failed to start MCP HTTP server:", error);
    process.exit(1);
  }
  console.log(`ChamCham MCP HTTP listening on :${PORT}/mcp`);
});
