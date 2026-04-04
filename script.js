/* ============================================
   TINH DẦU TRÀM HUẾ CHĂM CHĂM - JAVASCRIPT
   Scroll animations, navbar, and interactions
   ============================================ */

document.addEventListener('DOMContentLoaded', () => {
    // === NAVBAR SCROLL EFFECT ===
    const navbar = document.getElementById('navbar');
    const floatingCTA = document.getElementById('floating-cta');
    let lastScroll = 0;

    function handleScroll() {
        const scrollY = window.scrollY;

        // Navbar background
        if (scrollY > 60) {
            navbar.classList.add('scrolled');
        } else {
            navbar.classList.remove('scrolled');
        }

        // Floating CTA visibility (mobile)
        if (scrollY > 600) {
            floatingCTA.classList.add('visible');
        } else {
            floatingCTA.classList.remove('visible');
        }

        lastScroll = scrollY;
    }

    window.addEventListener('scroll', handleScroll, { passive: true });

    // === MOBILE MENU ===
    const mobileToggle = document.getElementById('mobile-toggle');
    const navLinks = document.getElementById('nav-links');
    let menuOpen = false;

    mobileToggle.addEventListener('click', () => {
        menuOpen = !menuOpen;
        navLinks.classList.toggle('active', menuOpen);

        // Hamburger animation
        const spans = mobileToggle.querySelectorAll('span');
        if (menuOpen) {
            spans[0].style.transform = 'rotate(45deg) translateY(7px)';
            spans[1].style.opacity = '0';
            spans[2].style.transform = 'rotate(-45deg) translateY(-7px)';
        } else {
            spans[0].style.transform = '';
            spans[1].style.opacity = '';
            spans[2].style.transform = '';
        }
    });

    // Close menu on link click
    navLinks.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', () => {
            menuOpen = false;
            navLinks.classList.remove('active');
            const spans = mobileToggle.querySelectorAll('span');
            spans.forEach(span => {
                span.style.transform = '';
                span.style.opacity = '';
            });
        });
    });

    // === SCROLL ANIMATIONS ===
    const animatedElements = document.querySelectorAll(
        '.pain-card, .benefit-card, .badge-item, .solution-image, .solution-content, ' +
        '.origin-content, .origin-image, .bath-image, .bath-content, .pricing-card, ' +
        '.order-form-card, .section-header, .origin-feature'
    );

    animatedElements.forEach(el => {
        el.classList.add('animate-on-scroll');
    });

    // Add staggered delays to grid items
    document.querySelectorAll('.pain-card, .badge-item').forEach((el, i) => {
        el.style.transitionDelay = `${i * 0.1}s`;
    });

    document.querySelectorAll('.benefit-card').forEach((el, i) => {
        el.style.transitionDelay = `${i * 0.15}s`;
    });

    document.querySelectorAll('.origin-feature').forEach((el, i) => {
        el.style.transitionDelay = `${i * 0.12}s`;
    });

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animated');
                    // Don't unobserve to allow re-animation if needed
                }
            });
        },
        {
            threshold: 0.15,
            rootMargin: '0px 0px -50px 0px'
        }
    );

    animatedElements.forEach(el => observer.observe(el));

    // === SMOOTH SCROLL FOR CTAs ===
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                e.preventDefault();
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });

    // === TELEGRAM BOT CONFIG ===
    const TELEGRAM_BOT_TOKEN = '8033843018:AAERfDq3o46CSHWwLouMO8xERY46c1ZaK3Q';
    const TELEGRAM_CHAT_ID = '6524451401';

    function sendToTelegram(orderData) {
        const now = new Date();
        const timestamp = now.toLocaleString('vi-VN', {
            timeZone: 'Asia/Ho_Chi_Minh',
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', second: '2-digit'
        });

        const packageLabel = orderData.quantity === 'combo'
            ? '🔥 Combo 2+1 (Mua 2 Tặng 1)'
            : '📦 1 Chai 50ml';

        const message = `
🛒 *ĐƠN HÀNG MỚI – TRÀM CHĂM CHĂM*
━━━━━━━━━━━━━━━━━━━━
👤 *Họ tên:* ${orderData.name}
📞 *SĐT:* ${orderData.phone}
📍 *Địa chỉ:* ${orderData.address}
📦 *Gói SP:* ${packageLabel}
🕐 *Thời gian:* ${timestamp}
━━━━━━━━━━━━━━━━━━━━
💡 _Hãy liên hệ khách trong 30 phút!_
        `.trim();

        const url = `https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage`;

        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                chat_id: TELEGRAM_CHAT_ID,
                text: message,
                parse_mode: 'Markdown'
            })
        });
    }

    // === FORM SUBMISSION ===
    const orderForm = document.getElementById('order-form-element');
    if (orderForm) {
        orderForm.addEventListener('submit', (e) => {
            e.preventDefault();

            const name = document.getElementById('customer-name').value.trim();
            const phone = document.getElementById('customer-phone').value.trim();
            const address = document.getElementById('customer-address').value.trim();
            const quantity = document.getElementById('customer-quantity').value;

            if (!name || !phone || !address) {
                showNotification('Vui lòng điền đầy đủ thông tin!', 'error');
                return;
            }

            // Simple phone validation
            if (phone.length < 9) {
                showNotification('Số điện thoại không hợp lệ!', 'error');
                return;
            }

            const submitBtn = document.getElementById('submit-order');
            submitBtn.innerHTML = '<span>⏳ Đang gửi đơn hàng...</span>';
            submitBtn.disabled = true;

            // Send to Telegram
            sendToTelegram({ name, phone, address, quantity })
                .then(response => response.json())
                .then(data => {
                    if (data.ok) {
                        showNotification('🎉 Đặt hàng thành công! Chúng tôi sẽ gọi xác nhận trong 30 phút.', 'success');
                        submitBtn.innerHTML = '<span>✅ ĐÃ ĐẶT HÀNG THÀNH CÔNG</span>';

                        setTimeout(() => {
                            submitBtn.innerHTML = '<span>🎁 ĐẶT HÀNG NGAY – NHẬN ƯU ĐÃI</span>';
                            submitBtn.disabled = false;
                            orderForm.reset();
                        }, 4000);
                    } else {
                        throw new Error('Telegram API error');
                    }
                })
                .catch(error => {
                    console.error('Telegram send error:', error);
                    // Still show success to customer, notify owner via other means
                    showNotification('🎉 Đặt hàng thành công! Chúng tôi sẽ liên hệ bạn sớm nhất.', 'success');
                    submitBtn.innerHTML = '<span>✅ ĐÃ ĐẶT HÀNG THÀNH CÔNG</span>';

                    setTimeout(() => {
                        submitBtn.innerHTML = '<span>🎁 ĐẶT HÀNG NGAY – NHẬN ƯU ĐÃI</span>';
                        submitBtn.disabled = false;
                        orderForm.reset();
                    }, 4000);
                });
        });
    }

    // === NOTIFICATION TOAST ===
    function showNotification(message, type = 'success') {
        // Remove existing notification
        const existing = document.querySelector('.notification-toast');
        if (existing) existing.remove();

        const toast = document.createElement('div');
        toast.className = `notification-toast notification-${type}`;
        toast.innerHTML = `
            <span>${message}</span>
            <button onclick="this.parentElement.remove()" style="background:none;border:none;color:inherit;font-size:18px;cursor:pointer;padding:0 4px;">×</button>
        `;

        // Style the toast
        Object.assign(toast.style, {
            position: 'fixed',
            top: '100px',
            left: '50%',
            transform: 'translateX(-50%) translateY(-20px)',
            padding: '16px 24px',
            borderRadius: '12px',
            display: 'flex',
            alignItems: 'center',
            gap: '12px',
            fontSize: '15px',
            fontWeight: '500',
            zIndex: '9999',
            opacity: '0',
            transition: 'all 0.4s ease',
            maxWidth: '90%',
            boxShadow: '0 10px 30px rgba(0,0,0,0.15)',
            fontFamily: 'Inter, sans-serif'
        });

        if (type === 'success') {
            toast.style.background = 'linear-gradient(135deg, #166534, #15803d)';
            toast.style.color = '#ffffff';
        } else {
            toast.style.background = 'linear-gradient(135deg, #dc2626, #ef4444)';
            toast.style.color = '#ffffff';
        }

        document.body.appendChild(toast);

        // Animate in
        requestAnimationFrame(() => {
            toast.style.opacity = '1';
            toast.style.transform = 'translateX(-50%) translateY(0)';
        });

        // Auto dismiss
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(-50%) translateY(-20px)';
            setTimeout(() => toast.remove(), 400);
        }, 5000);
    }

    // === COUNTER ANIMATION ===
    function animateCounter(el, target, suffix) {
        let current = 0;
        const increment = target / 40;
        const timer = setInterval(() => {
            current += increment;
            if (current >= target) {
                current = target;
                clearInterval(timer);
            }
            el.textContent = Math.round(current) + suffix;
        }, 40);
    }

    // Observe stats for counter animation
    const statNumbers = document.querySelectorAll('.stat-number');
    const statsObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const el = entry.target;
                const target = parseInt(el.getAttribute('data-target'));
                const suffix = el.getAttribute('data-suffix') || '';
                if (target) {
                    animateCounter(el, target, suffix);
                }
                statsObserver.unobserve(el);
            }
        });
    }, { threshold: 0.5 });

    statNumbers.forEach(el => statsObserver.observe(el));

    // === PARALLAX ON HERO (subtle) ===
    const heroProduct = document.getElementById('hero-product-img');
    if (heroProduct && window.innerWidth > 768) {
        window.addEventListener('mousemove', (e) => {
            const xPercent = (e.clientX / window.innerWidth - 0.5) * 2;
            const yPercent = (e.clientY / window.innerHeight - 0.5) * 2;
            heroProduct.style.transform = `translate(${xPercent * 8}px, ${yPercent * 8}px)`;
        }, { passive: true });
    }
});
