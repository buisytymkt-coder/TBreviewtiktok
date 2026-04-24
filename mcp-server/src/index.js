import { spawnSync } from "node:child_process";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { Server } from "@modelcontextprotocol/sdk/server/index.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
} from "@modelcontextprotocol/sdk/types.js";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const PROJECT_ROOT = path.resolve(__dirname, "..", "..");
const DB_PATH = process.env.BRAIN_DB_PATH || path.join(PROJECT_ROOT, "brain.db");
const WEBSITE_BASE_URL = (process.env.WEBSITE_BASE_URL || "https://shop.jocohome.shop").replace(/\/+$/, "");
const CHECKOUT_PATH = process.env.CHECKOUT_PATH || "/sepay_checkout.php";

function sqlQuote(value) {
  return `'${String(value).replace(/'/g, "''")}'`;
}

function runSql(sql, { json = false } = {}) {
  const args = [];
  if (json) {
    args.push("-json");
  }
  args.push(DB_PATH, sql);

  const result = spawnSync("sqlite3", args, {
    encoding: "utf8",
    maxBuffer: 1024 * 1024 * 10,
  });

  if (result.error) {
    throw new Error(`sqlite3 error: ${result.error.message}`);
  }
  if (result.status !== 0) {
    const stderr = (result.stderr || "").trim();
    throw new Error(`sqlite3 failed: ${stderr || "unknown error"}`);
  }

  const stdout = (result.stdout || "").trim();
  if (!json) {
    return stdout;
  }
  if (!stdout) {
    return [];
  }
  try {
    return JSON.parse(stdout);
  } catch {
    throw new Error(`sqlite3 JSON parse failed: ${stdout.slice(0, 400)}`);
  }
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
  const params = new URLSearchParams({
    invoice: invoiceNumber,
    resume: "1",
  });
  return `${WEBSITE_BASE_URL}${CHECKOUT_PATH}?${params.toString()}`;
}

function buildToolText(data) {
  return JSON.stringify(data, null, 2);
}

function parseCreateOrderArgs(args) {
  const customerName = String(args?.customer_name || "").trim();
  const customerContact = String(args?.customer_contact || "").trim();
  const customerEmail = String(args?.customer_email || "").trim();
  const itemName = String(args?.item_name || "").trim();
  const amountRaw = Number(args?.amount || 0);
  const note = String(args?.note || "").trim();

  if (!customerName || !customerContact || !itemName) {
    throw new Error("Thiếu dữ liệu bắt buộc: customer_name, customer_contact, item_name");
  }
  if (!Number.isFinite(amountRaw) || amountRaw < 2000) {
    throw new Error("amount phải là số hợp lệ và >= 2000");
  }

  return {
    customerName,
    customerContact,
    customerEmail,
    itemName,
    amount: Math.round(amountRaw),
    note,
  };
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

function getDailySalesSnapshot(args) {
  const date = String(args?.date || todayYmd()).trim();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
    throw new Error("date phải theo định dạng YYYY-MM-DD");
  }

  const totals = runSql(
    `
    SELECT
      COUNT(*) AS total_orders,
      COALESCE(SUM(amount), 0) AS total_revenue,
      COALESCE(SUM(CASE WHEN status IN ('paid','success') THEN amount ELSE 0 END), 0) AS paid_revenue
    FROM orders
    WHERE DATE(purchased_at) = ${sqlQuote(date)};
  `,
    { json: true }
  )[0] || { total_orders: 0, total_revenue: 0, paid_revenue: 0 };

  const byStatus = runSql(
    `
    SELECT status, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS revenue
    FROM orders
    WHERE DATE(purchased_at) = ${sqlQuote(date)}
    GROUP BY status
    ORDER BY count DESC;
  `,
    { json: true }
  );

  const topProducts = runSql(
    `
    SELECT
      COALESCE(product_name, 'Khác') AS product_name,
      COUNT(*) AS sold_count,
      COALESCE(SUM(amount), 0) AS revenue
    FROM orders
    WHERE DATE(purchased_at) = ${sqlQuote(date)}
    GROUP BY COALESCE(product_name, 'Khác')
    ORDER BY sold_count DESC, revenue DESC
    LIMIT 5;
  `,
    { json: true }
  );

  const latestOrders = runSql(
    `
    SELECT
      o.id,
      COALESCE(o.invoice_number, '') AS invoice_number,
      COALESCE(c.name, '') AS customer_name,
      o.product_name,
      o.amount,
      o.status,
      o.purchased_at
    FROM orders o
    LEFT JOIN customers c ON c.id = o.customer_id
    WHERE DATE(o.purchased_at) = ${sqlQuote(date)}
    ORDER BY o.id DESC
    LIMIT 5;
  `,
    { json: true }
  ).map((row) => ({ ...row, status_label: statusLabel(row.status) }));

  return {
    date,
    timezone: String(args?.timezone || "Asia/Ho_Chi_Minh"),
    total_orders: Number(totals.total_orders || 0),
    total_revenue: Number(totals.total_revenue || 0),
    paid_revenue: Number(totals.paid_revenue || 0),
    by_status: byStatus.map((row) => ({
      status: row.status,
      status_label: statusLabel(row.status),
      count: Number(row.count || 0),
      revenue: Number(row.revenue || 0),
    })),
    top_products: topProducts.map((row) => ({
      product_name: row.product_name,
      sold_count: Number(row.sold_count || 0),
      revenue: Number(row.revenue || 0),
    })),
    latest_orders: latestOrders,
  };
}

function createCheckoutOrder(args) {
  const parsed = parseCreateOrderArgs(args);
  const invoiceNumber = createInvoiceNumber();
  const email = parsed.customerEmail || (parsed.customerContact.includes("@") ? parsed.customerContact : "");
  const escapedPhone = sqlQuote(parsed.customerContact);
  const escapedEmail = email ? sqlQuote(email.toLowerCase()) : "NULL";
  const escapedName = sqlQuote(parsed.customerName);
  const escapedItem = sqlQuote(parsed.itemName);
  const escapedInvoice = sqlQuote(invoiceNumber);
  const escapedNote = sqlQuote(parsed.note || "Tạo từ MCP Telegram");

  const sql = `
    BEGIN IMMEDIATE TRANSACTION;
    INSERT INTO customers(name, phone, zalo, email, registered_at, created_at, updated_at)
    SELECT
      ${escapedName},
      ${escapedPhone},
      CASE WHEN instr(${escapedPhone}, '@') > 0 THEN NULL ELSE ${escapedPhone} END,
      ${escapedEmail},
      CURRENT_TIMESTAMP,
      CURRENT_TIMESTAMP,
      CURRENT_TIMESTAMP
    WHERE NOT EXISTS (
      SELECT 1 FROM customers
      WHERE phone = ${escapedPhone}
         OR (${escapedEmail} IS NOT NULL AND lower(email) = lower(${escapedEmail}))
    );

    UPDATE customers
    SET
      name = ${escapedName},
      email = CASE WHEN ${escapedEmail} IS NOT NULL THEN ${escapedEmail} ELSE email END,
      updated_at = CURRENT_TIMESTAMP
    WHERE phone = ${escapedPhone}
       OR (${escapedEmail} IS NOT NULL AND lower(email) = lower(${escapedEmail}));

    INSERT INTO orders(
      customer_id, product_id, product_name, amount, status, purchased_at, created_at, updated_at, invoice_number, payment_note
    )
    VALUES (
      (
        SELECT id
        FROM customers
        WHERE phone = ${escapedPhone}
           OR (${escapedEmail} IS NOT NULL AND lower(email) = lower(${escapedEmail}))
        ORDER BY id DESC
        LIMIT 1
      ),
      NULL,
      ${escapedItem},
      ${parsed.amount},
      'pending',
      CURRENT_TIMESTAMP,
      CURRENT_TIMESTAMP,
      CURRENT_TIMESTAMP,
      ${escapedInvoice},
      ${escapedNote}
    );

    SELECT
      o.id AS order_id,
      o.invoice_number,
      o.status,
      o.amount,
      c.id AS customer_id,
      c.name AS customer_name,
      c.phone AS customer_contact,
      c.email AS customer_email
    FROM orders o
    LEFT JOIN customers c ON c.id = o.customer_id
    WHERE o.invoice_number = ${escapedInvoice}
    LIMIT 1;
    COMMIT;
  `;

  const rows = runSql(sql, { json: true });
  if (!Array.isArray(rows) || rows.length === 0) {
    throw new Error("Không tạo được đơn hàng");
  }
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

function updateOrderFulfillmentStatus(args) {
  const orderId = Number(args?.order_id || 0);
  const invoiceNumber = String(args?.invoice_number || "").trim();
  const status = String(args?.status || "").trim().toLowerCase();
  const paymentNote = String(args?.payment_note || "").trim();

  if (!orderId && !invoiceNumber) {
    throw new Error("Cần truyền order_id hoặc invoice_number");
  }
  const allowed = new Set(["pending", "paid", "shipping", "cancelled", "failed", "success"]);
  if (!allowed.has(status)) {
    throw new Error("status không hợp lệ");
  }

  const whereClause = orderId
    ? `id = ${orderId}`
    : `invoice_number = ${sqlQuote(invoiceNumber)}`;

  const beforeRows = runSql(
    `
    SELECT id, invoice_number, status, amount, product_name, purchased_at, payment_note
    FROM orders
    WHERE ${whereClause}
    LIMIT 1;
  `,
    { json: true }
  );
  if (!beforeRows.length) {
    throw new Error("Không tìm thấy đơn hàng cần cập nhật");
  }
  const before = beforeRows[0];

  const sql = `
    UPDATE orders
    SET
      status = ${sqlQuote(status)},
      payment_note = CASE
        WHEN ${sqlQuote(paymentNote)} <> '' THEN ${sqlQuote(paymentNote)}
        ELSE payment_note
      END,
      paid_at = CASE
        WHEN ${sqlQuote(status)} IN ('paid','success') THEN COALESCE(paid_at, CURRENT_TIMESTAMP)
        ELSE paid_at
      END,
      updated_at = CURRENT_TIMESTAMP
    WHERE ${whereClause};

    SELECT id, invoice_number, status, amount, product_name, purchased_at, payment_note, paid_at, updated_at
    FROM orders
    WHERE ${whereClause}
    LIMIT 1;
  `;
  const afterRows = runSql(sql, { json: true });
  if (!afterRows.length) {
    throw new Error("Cập nhật thất bại");
  }
  const after = afterRows[0];

  return {
    before: {
      ...before,
      status_label: statusLabel(before.status),
    },
    after: {
      ...after,
      status_label: statusLabel(after.status),
    },
  };
}

const server = new Server(
  {
    name: "chamcham-ops",
    version: "1.0.0",
  },
  {
    capabilities: {
      tools: {},
    },
  }
);

server.setRequestHandler(ListToolsRequestSchema, async () => {
  return {
    tools: [
      {
        name: "get_daily_sales_snapshot",
        description: "Lấy snapshot doanh thu/ngày bán theo trạng thái đơn hàng và top sản phẩm.",
        inputSchema: {
          type: "object",
          properties: {
            date: { type: "string", description: "YYYY-MM-DD, mặc định hôm nay" },
            timezone: { type: "string", description: "Mặc định Asia/Ho_Chi_Minh" },
          },
          additionalProperties: false,
        },
      },
      {
        name: "create_checkout_order",
        description: "Tạo đơn pending trong brain.db và trả checkout_url để khách thanh toán SePay.",
        inputSchema: {
          type: "object",
          required: ["customer_name", "customer_contact", "item_name", "amount"],
          properties: {
            customer_name: { type: "string" },
            customer_contact: { type: "string" },
            customer_email: { type: "string" },
            item_name: { type: "string" },
            amount: { type: "number" },
            note: { type: "string" },
          },
          additionalProperties: false,
        },
      },
      {
        name: "update_order_fulfillment_status",
        description: "Cập nhật trạng thái vận hành đơn hàng (pending/paid/shipping/cancelled/failed).",
        inputSchema: {
          type: "object",
          properties: {
            order_id: { type: "number" },
            invoice_number: { type: "string" },
            status: { type: "string" },
            payment_note: { type: "string" },
          },
          required: ["status"],
          additionalProperties: false,
        },
      },
    ],
  };
});

server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const name = request.params.name;
  const args = request.params.arguments || {};
  try {
    let data;
    if (name === "get_daily_sales_snapshot") {
      data = getDailySalesSnapshot(args);
    } else if (name === "create_checkout_order") {
      data = createCheckoutOrder(args);
    } else if (name === "update_order_fulfillment_status") {
      data = updateOrderFulfillmentStatus(args);
    } else {
      throw new Error(`Tool không tồn tại: ${name}`);
    }

    return {
      content: [
        {
          type: "text",
          text: buildToolText({ ok: true, tool: name, data }),
        },
      ],
    };
  } catch (error) {
    return {
      isError: true,
      content: [
        {
          type: "text",
          text: buildToolText({
            ok: false,
            tool: name,
            error: error instanceof Error ? error.message : String(error),
          }),
        },
      ],
    };
  }
});

const transport = new StdioServerTransport();
await server.connect(transport);
