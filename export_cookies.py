import json, asyncio
from playwright.async_api import async_playwright

async def export_cookies():
    print("Mở Chrome... Hãy đăng nhập TikTok Seller Center")
    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=False)
        context = await browser.new_context(viewport={"width": 1440, "height": 900})
        page = await context.new_page()
        await page.goto("https://seller-us.tiktok.com/product/rating?shop_region=US")
        input("👆 Đăng nhập xong → nhấn Enter: ")
        cookies = await context.cookies()
        tiktok_cookies = [c for c in cookies if "tiktok" in c.get("domain","").lower()]
        await browser.close()
    with open("tiktok_cookies.json", "w") as f:
        json.dump(tiktok_cookies, f, indent=2)
    print(f"✅ Xuất {len(tiktok_cookies)} cookies → tiktok_cookies.json")
    print("\nBước tiếp: Copy nội dung file tiktok_cookies.json")
    print("→ Paste vào GitHub Secret: TIKTOK_COOKIES")

asyncio.run(export_cookies())
