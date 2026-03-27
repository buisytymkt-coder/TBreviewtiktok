import json, os, asyncio
from datetime import datetime
from pathlib import Path
from playwright.async_api import async_playwright

TELEGRAM_BOT_TOKEN = os.environ["TELEGRAM_BOT_TOKEN"]
TELEGRAM_CHAT_ID   = os.environ["TELEGRAM_CHAT_ID"]
TIKTOK_COOKIES_JSON = os.environ.get("TIKTOK_COOKIES", "")
STATE_FILE = Path("state.json")
TIKTOK_RATINGS_URL = "https://seller-us.tiktok.com/product/rating?shop_region=US"

# Selectors từ TikTok Seller Center thực tế
CONTAINER_SELECTOR = "#core-tabs-0-panel-0 > div > div > div:nth-child(4) > div.ratingListContainer-zqOVEf > div.ratingListMain-Njrazc > div.ratingListMainLists-J46EQM"
ITEM_SELECTOR = CONTAINER_SELECTOR + " > div"
ORDER_ID_SELECTOR = "div.productItem-CxPWQF > div > div.productItemInfoOrderId-NuCP_U > div"

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

async def get_text(el, selector):
    try:
        found = await el.query_selector(selector)
        if found:
            return (await found.inner_text()).strip()
    except:
        pass
    return ""

async def scrape_reviews():
    reviews = []
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
        print("Navigating...")
        try:
            await page.goto(TIKTOK_RATINGS_URL, wait_until="domcontentloaded", timeout=120000)
        except Exception as e:
            print(f"Goto error: {e}")
            await browser.close()
            return []

        await page.wait_for_timeout(12000)

        current_url = page.url
        print(f"URL: {current_url}")
        if "login" in current_url.lower() or "passport" in current_url.lower():
            print("Not logged in!")
            await browser.close()
            return []
        print("Logged in OK")

        # Chờ container review xuất hiện
        try:
            await page.wait_for_selector(CONTAINER_SELECTOR, timeout=20000)
            print("Container found!")
        except Exception as e:
            print(f"Container not found: {e}")
            # Thử selector đơn giản hơn
            try:
                await page.wait_for_selector("[class*='ratingListMainLists']", timeout=10000)
                print("Found via class selector!")
            except:
                print("No container found at all")
                await browser.close()
                return []

        # Lấy tất cả review items
        items = await page.query_selector_all(ITEM_SELECTOR)
        if not items:
            # Thử selector đơn giản hơn
            items = await page.query_selector_all("[class*='ratingListMainLists'] > div")
        print(f"Found {len(items)} review items")

        for i, item in enumerate(items):
            try:
                # Lấy Order ID
                order_id_el = await item.query_selector(ORDER_ID_SELECTOR)
                if not order_id_el:
                    order_id_el = await item.query_selector("[class*='OrderId'] div, [class*='orderId'] div")
                order_id = (await order_id_el.inner_text()).strip() if order_id_el else f"item_{i}"
                print(f"Item {i}: Order ID = {order_id}")

                # Lấy rating — đếm số sao vàng
                filled_stars = await item.query_selector_all("[class*='starIcon'] svg[color='#FFC200'], [class*='filled'], [fill='#FFC200']")
                rating = len(filled_stars)

                # Thử cách khác nếu không tìm được stars
                if rating == 0:
                    all_stars = await item.query_selector_all("[class*='star'], [class*='Star']")
                    rating_text = await get_text(item, "[aria-label*='star'], [title*='star'], [class*='ratingScore']")
                    if rating_text and rating_text.isdigit():
                        rating = int(rating_text)
                    else:
                        rating = len(all_stars) if all_stars else 0

                print(f"  Rating: {rating}")

                # Chỉ lấy 1★ 2★ 3★
                if rating == 0 or rating >= 4:
                    continue

                # Lấy review text
                text = await get_text(item, "[class*='reviewContent'], [class*='ReviewContent'], [class*='ratingContent']")
                if not text:
                    text = await get_text(item, "p, [class*='content']")

                # Lấy username
                username = await get_text(item, "[class*='username'], [class*='UserName'], [class*='buyerName']")

                # Lấy product name
                product = await get_text(item, "[class*='productName'], [class*='ProductName'], [class*='skuName']")

                # Lấy date
                date = await get_text(item, "[class*='date'], [class*='Date'], time")

                reviews.append({
                    "order_id": order_id,
                    "rating": rating,
                    "type": "negative" if rating <= 2 else "neutral",
                    "text": text[:300] if text else "",
                    "date": date,
                    "username": username or "Unknown",
                    "product": product[:80] if product else "Unknown",
                })
                print(f"  ✅ Added review: {rating}★ — {text[:50]}")

            except Exception as e:
                print(f"Item {i} error: {e}")

        await browser.close()
    print(f"Total scraped: {len(reviews)}")
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
    print(f"TikTok Review Monitor v4 — {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    for i in range(5):
        await run_once()
        if i < 4:
            print("Waiting 3 minutes...")
            await asyncio.sleep(180)

if __name__ == "__main__":
    asyncio.run(main())
