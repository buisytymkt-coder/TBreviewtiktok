import json, os, asyncio
from datetime import datetime
from pathlib import Path
from playwright.async_api import async_playwright

TELEGRAM_BOT_TOKEN = os.environ["TELEGRAM_BOT_TOKEN"]
TELEGRAM_CHAT_ID   = os.environ["TELEGRAM_CHAT_ID"]
TIKTOK_COOKIES_JSON = os.environ.get("TIKTOK_COOKIES", "")
STATE_FILE = Path("state.json")
TIKTOK_RATINGS_URL = "https://seller-us.tiktok.com/product/rating?shop_region=US"

def load_state():
    if STATE_FILE.exists():
        return json.loads(STATE_FILE.read_text())
    return {"seen_order_ids": []}

def save_state(state):
    STATE_FILE.write_text(json.dumps(state, indent=2))

async def send_telegram(message):
    import urllib.request, urllib.parse
    url = f"https://api.telegram.org/bot{TELEGRAM_BOT_TOKEN}/sendMessage"
    data = urllib.parse.urlencode({
        "chat_id": TELEGRAM_CHAT_ID,
        "text": message,
        "parse_mode": "HTML",
    }).encode()
    req = urllib.request.Request(url, data=data, method="POST")
    with urllib.request.urlopen(req, timeout=10) as resp:
        result = json.loads(resp.read())
        print("Telegram OK" if result.get("ok") else f"Telegram error: {result}")

def build_message(review):
    stars = "⭐" * review["rating"] + "☆" * (5 - review["rating"])
    emoji = "🔴" if review["type"] == "negative" else "🟡"
    label = "NEGATIVE" if review["type"] == "negative" else "NEUTRAL"
    return (
        f"{emoji} <b>{label} Review mới — TikTok Shop</b>\n\n"
        f"{stars} ({review['rating']}/5)\n"
        f"👤 <b>User:</b> {review['username']}\n"
        f"📦 <b>Product:</b> {review['product']}\n"
        f"💬 <b>Review:</b> {review['text']}\n"
        f"🗓 <b>Date:</b> {review['date']}\n"
        f"🔗 <b>Order ID:</b> <code>{review['order_id']}</code>\n\n"
        f"👉 <a href='{TIKTOK_RATINGS_URL}'>Reply ngay trên Seller Center</a>"
    )

async def scrape_reviews():
    reviews = []
    async with async_playwright() as p:
        browser = await p.chromium.launch(
            headless=True,
            args=[
                "--no-sandbox",
                "--disable-blink-features=AutomationControlled",
                "--disable-dev-shm-usage",
            ]
        )
        context = await browser.new_context(
            user_agent="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36",
            viewport={"width": 1440, "height": 900},
            locale="en-US",
        )
        if TIKTOK_COOKIES_JSON:
            cookies = json.loads(TIKTOK_COOKIES_JSON)
            await context.add_cookies(cookies)
            print(f"Injected {len(cookies)} cookies")

        page = await context.new_page()

        # Tăng timeout lên 120s, đổi wait_until sang domcontentloaded (nhanh hơn networkidle)
        print(f"Navigating to TikTok Seller Center...")
        try:
            await page.goto(TIKTOK_RATINGS_URL, wait_until="domcontentloaded", timeout=120000)
        except Exception as e:
            print(f"Goto error: {e}")
            await browser.close()
            return []

        # Chờ thêm để JS load xong
        await page.wait_for_timeout(8000)

        current_url = page.url
        print(f"Current URL: {current_url}")

        if "login" in current_url.lower() or "passport" in current_url.lower():
            print("Not logged in — cookies expired")
            await browser.close()
            return []

        print("Logged in OK")

        # Chờ bảng review xuất hiện
        try:
            await page.wait_for_selector("table tbody tr", timeout=20000)
        except Exception:
            print("Table not found — TikTok may have changed layout")
            await browser.close()
            return []

        rows = await page.query_selector_all("table tbody tr")
        print(f"Found {len(rows)} rows")

        for row in rows:
            try:
                star_els = await row.query_selector_all("[class*='filled']")
                rating = len(star_els) if star_els else 0
                if rating >= 4:
                    continue
                text_el = await row.query_selector("td:nth-child(1) p")
                text = (await text_el.inner_text()).strip() if text_el else ""
                date_el = await row.query_selector("time, [class*='date']")
                date = (await date_el.inner_text()).strip() if date_el else ""
                user_el = await row.query_selector("[class*='username']")
                username = (await user_el.inner_text()).strip() if user_el else "Unknown"
                order_el = await row.query_selector("[class*='order']")
                order_text = (await order_el.inner_text()).strip() if order_el else ""
                order_id = order_text.split("Order ID:")[-1].split("\n")[0].strip() if "Order ID:" in order_text else f"row_{len(reviews)}"
                product_el = await row.query_selector("[class*='product']")
                product = (await product_el.inner_text()).strip() if product_el else "Unknown"
                reviews.append({
                    "order_id": order_id,
                    "rating": rating,
                    "type": "negative" if rating <= 2 else "neutral",
                    "text": text[:300],
                    "date": date,
                    "username": username,
                    "product": product[:80],
                })
            except Exception as e:
                print(f"Row error: {e}")
        await browser.close()
    return reviews

async def main():
    print(f"TikTok Review Monitor — {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    state = load_state()
    seen_ids = set(state.get("seen_order_ids", []))
    reviews = await scrape_reviews()
    new_reviews = [r for r in reviews if r["order_id"] not in seen_ids]
    print(f"New reviews: {len(new_reviews)}")
    for review in new_reviews:
        await send_telegram(build_message(review))
        seen_ids.add(review["order_id"])
    if not new_reviews:
        print("No new negative/neutral reviews")
    save_state({"seen_order_ids": list(seen_ids), "last_run": datetime.now().isoformat()})

if __name__ == "__main__":
    asyncio.run(main())
