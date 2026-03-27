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
            args=["--no-sandbox", "--disable-blink-features=AutomationControlled", "--disable-dev-shm-usage"]
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

        # Chờ JS render xong — tăng lên 15 giây
        print("Waiting for JS to render...")
        await page.wait_for_timeout(15000)

        current_url = page.url
        print(f"Current URL: {current_url}")

        if "login" in current_url.lower() or "passport" in current_url.lower():
            print("Not logged in — cookies expired")
            await browser.close()
            return []

        print("Logged in OK")

        # Thử scroll để trigger lazy load
        await page.evaluate("window.scrollTo(0, 500)")
        await page.wait_for_timeout(3000)

        # Dump tất cả class names để tìm đúng selector
        all_classes = await page.evaluate("""
            () => {
                const els = document.querySelectorAll('*');
                const classes = new Set();
                els.forEach(el => {
                    el.classList.forEach(c => {
                        if (c.length > 3 && c.length < 50) classes.add(c);
                    });
                });
                return Array.from(classes).slice(0, 200);
            }
        """)
        print(f"Classes found: {[c for c in all_classes if any(k in c.lower() for k in ['review', 'rating', 'star', 'table', 'row', 'item', 'list'])]}")

        # Thử tìm rows bằng nhiều cách
        selectors = [
            "table tbody tr",
            "tr[class]",
            "[class*='review']",
            "[class*='Review']",
            "[class*='rating']",
            "[class*='Rating']",
            "[class*='RatingTable']",
            "[class*='TableRow']",
            "[class*='tableRow']",
            "[class*='row']",
        ]

        rows = []
        used_selector = ""
        for sel in selectors:
            rows = await page.query_selector_all(sel)
            if rows:
                print(f"✅ Found {len(rows)} elements with: {sel}")
                used_selector = sel
                break
            else:
                print(f"❌ No match: {sel}")

        if not rows:
            # Lưu screenshot để debug
            print("No rows found at all!")
            await browser.close()
            return []

        print(f"Processing {len(rows)} rows...")
        for i, row in enumerate(rows[:20]):  # Chỉ xử lý 20 rows đầu
            try:
                row_text = await row.inner_text()
                print(f"Row {i}: {row_text[:150]}")

                # Tìm rating từ aria-label hoặc title của stars
                rating = 0
                star_filled = await row.query_selector_all("[class*='filled'], [class*='active'], [aria-label*='star'], [title*='star']")
                if star_filled:
                    rating = len(star_filled)
                else:
                    # Thử đọc từ text — vd "3 out of 5"
                    import re
                    match = re.search(r'(\d)\s*(?:out of\s*5|/\s*5|\s*star)', row_text.lower())
                    if match:
                        rating = int(match.group(1))

                print(f"  → Rating: {rating}")
                if rating == 0 or rating >= 4:
                    continue

                reviews.append({
                    "order_id": f"row_{i}_{hash(row_text[:50])}",
                    "rating": rating,
                    "type": "negative" if rating <= 2 else "neutral",
                    "text": row_text[:300],
                    "date": datetime.now().strftime("%Y-%m-%d"),
                    "username": "Unknown",
                    "product": "Unknown",
                })
            except Exception as e:
                print(f"Row {i} error: {e}")

        await browser.close()
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
