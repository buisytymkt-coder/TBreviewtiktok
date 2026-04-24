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
        '.order-form-card, .section-header, .origin-feature, .survey-form-card'
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

    // === FORM SUBMISSION ===
    const orderForm = document.getElementById('order-form-element');
    const sepayPayBtn = document.getElementById('pay-with-sepay');

    function getOrderFormData() {
        const name = document.getElementById('customer-name').value.trim();
        const phone = document.getElementById('customer-phone').value.trim();
        const address = document.getElementById('customer-address').value.trim();
        const quantity = document.getElementById('customer-quantity').value;
        return { name, phone, address, quantity };
    }

    function validateOrderFormData(orderData) {
        if (!orderData.name || !orderData.phone || !orderData.address) {
            showNotification('Vui lòng điền đầy đủ thông tin!', 'error');
            return false;
        }

        if (orderData.phone.length < 9) {
            showNotification('Số điện thoại không hợp lệ!', 'error');
            return false;
        }

        return true;
    }

    function redirectToSepay(orderData) {
        const packagePricing = {
            '1': 198000,
            combo: 350000
        };

        const amount = packagePricing[orderData.quantity] || 198000;
        const packageLabel = orderData.quantity === 'combo'
            ? 'Combo 2 chai 50ml'
            : '1 chai 50ml';
        const description = `Thanh toán ${packageLabel} - ${orderData.name} - ${orderData.phone}`;

        const params = new URLSearchParams({
            auto: '1',
            amount: String(amount),
            description,
            customer_name: orderData.name,
            customer_phone: orderData.phone,
            customer_address: orderData.address,
            package: packageLabel
        });

        window.location.href = `/thanh-toan/?${params.toString()}`;
    }

    if (orderForm) {
        orderForm.addEventListener('submit', (e) => {
            e.preventDefault();

            const orderData = getOrderFormData();
            if (!validateOrderFormData(orderData)) {
                return;
            }

            const submitBtn = document.getElementById('submit-order');
            submitBtn.innerHTML = '<span>⏳ Đang chuyển sang SePay...</span>';
            submitBtn.disabled = true;

            redirectToSepay(orderData);
        });
    }

    if (sepayPayBtn) {
        sepayPayBtn.addEventListener('click', () => {
            const orderData = getOrderFormData();
            if (!validateOrderFormData(orderData)) {
                return;
            }
            redirectToSepay(orderData);
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

    // === SURVEY FORM ===
    const surveyForm = document.getElementById('survey-form-element');
    const surveyProgressBar = document.getElementById('survey-progress-bar');
    const GOOGLE_FORM_ACTION_URL = 'https://docs.google.com/forms/d/e/1FAIpQLScK098ai5xjACDhUy4nRa1vw83Qkj3I2IzpBrXfEaEKfFea6A/formResponse';
    const GOOGLE_FORM_FIELDS = {
        name: 'entry.671398457',
        phone: 'entry.1516345113',
        // Add Google Form entry id here when email question is created on Google Form
        email: '',
        babyAge: 'entry.56119149',
        usage: 'entry.1777380871',
        concern: 'entry.359564329'
    };

    if (surveyForm && surveyProgressBar) {
        // Track progress
        function updateSurveyProgress() {
            const totalFields = 6; // name, phone, email, baby_age, usage, concern
            let filledFields = 0;

            const nameVal = document.getElementById('survey-name').value.trim();
            const phoneVal = document.getElementById('survey-phone').value.trim();
            const emailVal = document.getElementById('survey-email').value.trim();
            const ageChecked = surveyForm.querySelector('input[name="baby_age"]:checked');
            const usageChecked = surveyForm.querySelector('input[name="usage"]:checked');
            const concernChecked = surveyForm.querySelector('input[name="concern"]:checked');

            if (nameVal) filledFields++;
            if (phoneVal) filledFields++;
            if (emailVal) filledFields++;
            if (ageChecked) filledFields++;
            if (usageChecked) filledFields++;
            if (concernChecked) filledFields++;

            const percent = (filledFields / totalFields) * 100;
            surveyProgressBar.style.width = percent + '%';

            // Keep radio selected state styling compatible across hosts/browsers
            surveyForm.querySelectorAll('.survey-radio-label').forEach(label => {
                const radio = label.querySelector('input[type="radio"]');
                label.classList.toggle('is-checked', !!(radio && radio.checked));
            });
        }

        // Listen for changes
        surveyForm.querySelectorAll('input').forEach(input => {
            input.addEventListener('input', updateSurveyProgress);
            input.addEventListener('change', updateSurveyProgress);
        });

        // Send survey data to Google Form -> Google Sheets
        function sendSurveyToGoogleForm(data) {
            const payload = {
                [GOOGLE_FORM_FIELDS.name]: data.name,
                [GOOGLE_FORM_FIELDS.phone]: data.phone,
                [GOOGLE_FORM_FIELDS.babyAge]: data.babyAge,
                [GOOGLE_FORM_FIELDS.usage]: data.usage,
                [GOOGLE_FORM_FIELDS.concern]: data.concern
            };
            if (GOOGLE_FORM_FIELDS.email) {
                payload[GOOGLE_FORM_FIELDS.email] = data.email;
            }
            const formBody = new URLSearchParams(payload).toString();

            return fetch(GOOGLE_FORM_ACTION_URL, {
                method: 'POST',
                mode: 'no-cors',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: formBody
            });
        }

        function sendSurveyToBackend(data) {
            return fetch('waitlist_submit.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            }).then(async (response) => {
                const body = await response.json().catch(() => ({}));
                if (!response.ok || !body.success) {
                    throw new Error(body.message || 'Không thể gửi waitlist lên server');
                }
                return body;
            });
        }

        // Handle survey submit
        surveyForm.addEventListener('submit', (e) => {
            e.preventDefault();

            const name = document.getElementById('survey-name').value.trim();
            const phone = document.getElementById('survey-phone').value.trim();
            const email = document.getElementById('survey-email').value.trim();
            const ageChecked = surveyForm.querySelector('input[name="baby_age"]:checked');
            const usageChecked = surveyForm.querySelector('input[name="usage"]:checked');
            const concernChecked = surveyForm.querySelector('input[name="concern"]:checked');

            if (!name || !phone || !email) {
                showNotification('Vui lòng điền tên, số điện thoại và email!', 'error');
                return;
            }

            if (phone.length < 9) {
                showNotification('Số điện thoại không hợp lệ!', 'error');
                return;
            }
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                showNotification('Email không hợp lệ!', 'error');
                return;
            }

            if (!ageChecked || !usageChecked || !concernChecked) {
                showNotification('Vui lòng chọn đầy đủ các câu trả lời!', 'error');
                return;
            }

            const submitBtn = document.getElementById('submit-survey');
            submitBtn.innerHTML = '<span>⏳ Đang gửi thông tin...</span>';
            submitBtn.disabled = true;

            const surveyData = {
                name,
                phone,
                email,
                babyAge: ageChecked.value,
                usage: usageChecked.value,
                concern: concernChecked.value
            };

            sendSurveyToBackend(surveyData)
                .then(() => sendSurveyToGoogleForm(surveyData).catch(() => null))
                .then(() => {
                    // Hide form, show success
                    surveyForm.style.display = 'none';
                    document.getElementById('survey-success').style.display = 'block';
                    surveyProgressBar.style.width = '100%';
                    showNotification('🎉 Cảm ơn bạn! Chúng tôi sẽ liên hệ tư vấn sớm nhất.', 'success');
                })
                .catch(error => {
                    console.error('Survey submit error:', error);
                    submitBtn.innerHTML = '<span>✨ GỬI THÔNG TIN – NHẬN TƯ VẤN MIỄN PHÍ</span>';
                    submitBtn.disabled = false;
                    showNotification(error.message || 'Gửi thông tin thất bại, vui lòng thử lại.', 'error');
                });
        });
    }

    // === CHATBOT (RULE-BASED FROM SALES SCRIPT) ===
    const chatbotWidget = document.getElementById('chatbot-widget');
    const chatbotToggle = document.getElementById('chatbot-toggle');
    const chatbotClose = document.getElementById('chatbot-close');
    const chatbotWindow = document.getElementById('chatbot-window');
    const chatbotMessages = document.getElementById('chatbot-messages');
    const chatbotForm = document.getElementById('chatbot-form');
    const chatbotInput = document.getElementById('chatbot-input');
    const chatbotQuickList = document.getElementById('chatbot-quick-list');
    const chatbotActions = document.getElementById('chatbot-actions');

    if (
        chatbotWidget &&
        chatbotToggle &&
        chatbotClose &&
        chatbotWindow &&
        chatbotMessages &&
        chatbotForm &&
        chatbotInput &&
        chatbotQuickList &&
        chatbotActions
    ) {
        let greeted = false;

        const greetingText =
            'Chào chị em ơi, Trâm từ Chăm Chăm đây ạ. Chị cần Trâm tư vấn nhanh theo nhu cầu của bé hay của mẹ để chọn đúng gói luôn không nè?';
        const buyClosingText =
            'Nếu chị đang nghiêng về mua rồi thì Trâm chốt đơn cho mình luôn để giữ ưu đãi hôm nay nhé. Chị gửi giúp Trâm tên + SĐT + địa chỉ nhận hàng, và chọn gói 1 chai 50ml hoặc Combo 2 chai 50ml là bên Trâm lên đơn liền.';
        const waitlistGuideText =
            'Dạ không sao chị em nhé, mình chưa chốt liền cũng được. Chị để lại form 30 giây giúp Trâm, tụi mình tư vấn đúng nhu cầu, không ép mua đâu ạ.';

        const faqAnswers = [
            {
                keywords: ['so sinh', 'tre so sinh', 'be so sinh', 'em be'],
                answer:
                    'Dạ có chị nhé. Tràm Chăm Chăm dùng được cho bé sơ sinh. Với bé da nhạy cảm thì chị dùng lượng ít, test trước vùng da nhỏ hoặc pha dầu nền cho yên tâm ạ.'
            },
            {
                keywords: ['me bau', 'sau sinh', 'bau bi'],
                answer:
                    'Dạ dùng được chị ạ. Nhiều mẹ bầu, mẹ sau sinh dùng để giữ ấm, xoa cổ vai gáy nhẹ hoặc xông phòng cho dễ chịu.'
            },
            {
                keywords: ['nguyen chat', 'pha tap', 'nguon goc', 'phu loc', 'hue'],
                answer:
                    'Bên Trâm đi theo tràm gió Phú Lộc - Huế, chưng cất thủ công và định hướng 100% tràm gió nguyên chất như trên website. Chị cần thì Trâm gửi ảnh chai thật để xem kỹ nha.'
            },
            {
                keywords: ['cach dung', 'dung sao', 'dung nhu the nao', 'nhu rang'],
                answer:
                    'Cách dễ nhất là thoa ít vào lưng, ngực, lòng bàn chân sau tắm tối. Ngoài ra có thể nhỏ vào nước ấm để tắm hoặc xông phòng nhẹ. Da nhạy cảm thì mình pha loãng trước chị nhé.'
            },
            {
                keywords: ['muoi', 'con trung', 'dot', 'xua muoi'],
                answer:
                    'Dạ có thể hỗ trợ chị nha. Chị nhỏ vào đèn xông hoặc bông gòn để xua côn trùng; khi bé bị đốt thì thoa lượng nhỏ vùng xung quanh để bé đỡ khó chịu.'
            },
            {
                keywords: ['da nhay cam', 'nong rat', 'kich ung'],
                answer:
                    'Da nhạy cảm thì nên test trước 1 điểm nhỏ 24h. Nếu cần, chị pha với dầu nền (dầu dừa hoặc dầu oliu) để dùng êm hơn nhiều.'
            },
            {
                keywords: ['goi', 'combo', 'mua may chai', '1 chai', '2+1'],
                answer:
                    'Hiện có 2 gói chính: 1 chai 50ml giá 198.000đ và Combo 2 chai 50ml giá 350.000đ (tiết kiệm hơn mua lẻ).'
            },
            {
                keywords: ['han su dung', 'hsd', 'bao lau'],
                answer:
                    'Dạ hạn dùng 2 năm từ ngày sản xuất chị nhé. Mình để nơi khô thoáng, đậy nắp kín sau khi dùng là ổn ạ.'
            },
            {
                keywords: ['giao hang', 'toan quoc', 'cod', 'thanh toan'],
                answer:
                    'Dạ bên Trâm giao toàn quốc. Chị điền form rồi thanh toán online qua SePay để hệ thống giữ đơn tự động ngay cho mình.'
            },
            {
                keywords: ['online', 'khong giong hinh', 'sai hang', 'that gia'],
                answer:
                    'Tâm lý này nhiều chị em hỏi lắm ạ. Trâm có thể gửi ảnh/video chai thật trước khi chốt. Đơn được xác nhận rõ ràng, nhận hàng kiểm tra rồi thanh toán nên yên tâm hơn chị nhé.'
            }
        ];

        const buyIntentKeywords = [
            'muon mua',
            'chot don',
            'len don',
            'dat hang',
            'mua ngay',
            'lay combo',
            'lay 1 chai',
            'tu van mua',
            'gia sao'
        ];

        const notReadyKeywords = [
            'de suy nghi',
            'chua mua',
            'xem them',
            'tham khao',
            'de sau',
            'chua san sang'
        ];

        function normalizeText(text) {
            return text
                .toLowerCase()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .replace(/đ/g, 'd')
                .trim();
        }

        function containsAny(text, keywords) {
            return keywords.some(keyword => text.includes(keyword));
        }

        function appendMessage(text, sender = 'bot') {
            const msg = document.createElement('div');
            msg.className = `chatbot-message ${sender}`;
            msg.textContent = text;
            chatbotMessages.appendChild(msg);
            chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
        }

        function showWaitlistButton(show) {
            chatbotActions.hidden = !show;
        }

        function getBotReply(userTextRaw) {
            const userText = normalizeText(userTextRaw);

            if (containsAny(userText, buyIntentKeywords)) {
                return { text: buyClosingText, showWaitlist: true };
            }

            if (containsAny(userText, notReadyKeywords)) {
                return { text: waitlistGuideText, showWaitlist: false };
            }

            const matched = faqAnswers.find(item => containsAny(userText, item.keywords));
            if (matched) {
                return { text: matched.answer, showWaitlist: false };
            }

            return {
                text: 'Trâm hiểu rồi chị. Nếu chị nói giúp Trâm nhà mình đang ưu tiên giữ ấm cho bé, xông phòng hay mua combo tiết kiệm thì Trâm tư vấn đúng nhu cầu liền nhé.',
                showWaitlist: false
            };
        }

        function sendUserMessage(messageText) {
            const text = messageText.trim();
            if (!text) return;

            appendMessage(text, 'user');
            const botReply = getBotReply(text);
            setTimeout(() => {
                appendMessage(botReply.text, 'bot');
                showWaitlistButton(botReply.showWaitlist);
            }, 250);
        }

        function openChatbot() {
            chatbotWidget.classList.add('open');
            chatbotWindow.setAttribute('aria-hidden', 'false');
            chatbotInput.focus();

            if (!greeted) {
                appendMessage(greetingText, 'bot');
                greeted = true;
            }
        }

        function closeChatbot() {
            chatbotWidget.classList.remove('open');
            chatbotWindow.setAttribute('aria-hidden', 'true');
        }

        chatbotToggle.addEventListener('click', () => {
            if (chatbotWidget.classList.contains('open')) {
                closeChatbot();
            } else {
                openChatbot();
            }
        });

        chatbotClose.addEventListener('click', closeChatbot);

        chatbotForm.addEventListener('submit', (e) => {
            e.preventDefault();
            sendUserMessage(chatbotInput.value);
            chatbotInput.value = '';
        });

        chatbotQuickList.addEventListener('click', (e) => {
            const target = e.target;
            if (!(target instanceof HTMLButtonElement)) return;
            const question = target.getAttribute('data-question');
            if (!question) return;
            sendUserMessage(question);
        });
    }

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
