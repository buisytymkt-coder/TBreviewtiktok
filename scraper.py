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
    api_responses = []

    async with async_playwright() as p:
        browser = await p.chromium.launch(
            headless=True,
            args=["--no-sandbox", "--disable-dev-shm-usage"]
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

        # Intercept tất cả network responses để tìm API review
        async def handle_response(response):
            url = response.url
            # Tìm các API call liên quan đến review/rating
            if any(k in url.lower() for k in ["review", "rating", "comment", "feedback"]):
                print(f"API found: {url[:120]}")
                try:
                    body = await response.json()
                    api_responses.append({"url": url, "data": body})
                except:
                    pass

        page.on("response", handle_response)

        print("Navigating...")
        try:
            await page.goto(TIKTOK_RATINGS_URL, wait_until="domcontentloaded", timeout=120000)
        except Exception as e:
            print(f"Goto error: {e}")
            await browser.close()
            return []

        # Chờ page load và API calls hoàn thành
        print("Waiting for API calls...")
        await page.wait_for_timeout(15000)

        # Scroll để trigger thêm data load
        await page.evaluate("window.scrollTo(0, 800)")
        await page.wait_for_timeout(5000)

        print(f"Total API responses captured: {len(api_responses)}")

        # Parse data từ API responses
        for resp in api_responses:
            url = resp["url"]
            data = resp["data"]
            print(f"Parsing: {url[:80]}")
            print(f"Keys: {list(data.keys()) if isinstance(data, dict) else 'list'}")

            try:
                # TikTok API thường trả về dạng {"data": {"list": [...]}}
                items = []
                if isinstance(data, dict):
                    # Thử các path phổ biến
                    for path in [
                        data.get("data", {}).get("list", []),
                        data.get("data", {}).get("reviews", []),
                        data.get("data", {}).get("items", []),
                        data.get("list", []),
                        data.get("reviews", []),
                    ]:
                        if path:
                            items = path
                            break

                print(f"Items found: {len(items)}")

                for item in items:
                    print(f"Item keys: {list(item.keys()) if isinstance(item, dict) else str(item)[:100]}")

                    # Lấy rating
                    rating = item.get("star", item.get("rating", item.get("score", 0)))
                    if isinstance(rating, str):
                        try:
                            rating = int(rating)
                        except:
                            rating = 0

                    if rating == 0 or rating >= 4:
                        continue

                    # Lấy các field khác
                    order_id = str(item.get("order_id", item.get("orderId", item.get("id", f"api_{len(reviews)}"))))
                    text = item.get("content", item.get("comment", item.get("review_content", item.get("text", ""))))
                    username = item.get("buyer_name", item.get("username", item.get("user_name", item.get("nickname", "Unknown"))))
                    product = item.get("product_name", item.get("productName", item.get("sku_name", "Unknown")))
                    date = item.get("create_time", item.get("createTime", item.get("date", "")))

                    if isinstance(date, (int, float)):
                        from datetime import datetime as dt
                        date = dt.fromtimestamp(date).strftime("%Y-%m-%d")

                    reviews.append({
                        "order_id": order_id,
                        "rating": rating,
                        "type": "negative" if rating <= 2 else "neutral",
                        "text": str(text)[:300],
                        "date": str(date),
                        "username": str(username),
                        "product": str(product)[:80],
                    })

            except Exception as e:
                print(f"Parse error: {e}")

        await browser.close()

    print(f"Total reviews scraped: {len(reviews)}")
    return reviews

async def run_once():
    print(f"\n--- Run at {datetime.now().strftime('%H:%M:%S')} ---")
    state = load_state()
    seen_ids = set(state.get("seen_order_ids", []))
    reviews = await scrape_reviews()
    new_reviews = [r for r in reviews if r["order_id"] not in seen_ids]
    print(f"New reviews to notify: {len(new_reviews)}")
    for review in new_reviews:
        await send_telegram(build_message(review))
        seen_ids.add(review["order_id"])
    if not new_reviews:
        print("No new negative/neutral reviews")
    save_state({"seen_order_ids": list(seen_ids), "last_run": datetime.now().isoformat()})

async def main():
    print(f"TikTok Review Monitor — {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    for i in range(5):
        await run_once()
        if i < 4:
            print("Waiting 3 minutes...")
            await asyncio.sleep(180)

if __name__ == "__main__":
    asyncio.run(main())
