(() => {
    const root = document.getElementById('tms-ai-chat-root');
    const toggle = document.getElementById('tms-ai-chat-toggle');
    const panel = document.getElementById('tms-ai-chat-panel');
    const close = document.getElementById('tms-ai-chat-close');
    const messages = document.getElementById('tms-ai-chat-messages');
    const quickChips = document.getElementById('tms-ai-chat-quick-chips');
    const form = document.getElementById('tms-ai-chat-form');
    const input = document.getElementById('tms-ai-chat-input');
    const send = document.getElementById('tms-ai-chat-send');
    const errorBox = document.getElementById('tms-ai-chat-error');
    if (!root || !toggle || !panel || !close || !messages || !quickChips || !form || !input || !send || !errorBox) return;

    const history = [];
    const predefinedFaqs = {
        vi: [
            [
                '📊 Trạng thái dịch: hướng dẫn tôi xem tiến độ, số câu đã dịch và số câu còn lại trong dự án.',
                'Để xem tiến độ, hãy mở dự án và chọn đúng phiên bản cùng ngôn ngữ đích. Khu vực thống kê và bộ lọc trạng thái giúp bạn theo dõi các câu đã dịch, đang chờ duyệt hoặc còn trống. Dữ liệu chi tiết phụ thuộc vào dự án bạn đang mở.'
            ],
            [
                '🔄 Lỗi đồng bộ API: giúp tôi kiểm tra các nguyên nhân thường gặp và cách khắc phục.',
                'Bạn có thể kiểm tra lần lượt: URL và phương thức API; trạng thái phản hồi cùng nội dung JSON; token xác thực; quyền truy cập; và kết nối mạng/TLS. Nếu lỗi chỉ xảy ra với một số gói, hãy kiểm tra log phản hồi đã loại bỏ thông tin bí mật trước khi thử lại.'
            ],
            [
                '💡 Hướng dẫn phím tắt: cho tôi biết các cách thao tác nhanh khi dịch và rà soát.',
                'Hiện chưa có danh sách phím tắt riêng được cấu hình trong TMS. Bạn có thể dùng các thao tác tiêu chuẩn của trình duyệt như Tab/Shift+Tab để di chuyển giữa trường, Enter để gửi trong khung chat, và Shift+Enter để xuống dòng.'
            ],
            [
                '⚙️ Quản lý dự án: hướng dẫn tôi cách tổ chức và quản lý dự án dịch thuật.',
                'Trong Workspace, tạo hoặc mở dự án để quản lý phiên bản nguồn, ngôn ngữ đích và thành viên. Phân quyền theo vai trò, nhập nguồn trước khi dịch, rồi theo dõi trạng thái để rà soát và phê duyệt các bản dịch.'
            ]
        ],
        en: [
            [
                '📊 Translation status: show me how to check progress, translated sentences, and what remains in the project.',
                'To review progress, open a project and select the target locale and source version. The statistics and status filters help you find translated, pending-review, and empty entries. Exact counts depend on the project you have open.'
            ],
            [
                '🔄 API sync error: help me check common causes and troubleshooting steps.',
                'Check the API URL and method, response status and JSON body, authentication token, access permissions, and network/TLS connectivity. If only some batches fail, review the response logs after removing secrets before retrying.'
            ],
            [
                '💡 Keyboard shortcuts: tell me ways to work faster while translating and reviewing.',
                'There is no separately configured TMS shortcut list at this time. Standard browser keyboard navigation is available: use Tab/Shift+Tab to move between fields, Enter to send in this chat, and Shift+Enter for a new line.'
            ],
            [
                '⚙️ Project management: guide me through organizing and managing a translation project.',
                'In Workspace, create or open a project to manage its source versions, target locales, and members. Assign roles, import source content before translating, then track statuses to review and approve translations.'
            ]
        ]
    };
    let pointerStart = null;
    let moved = false;
    let suppressClick = false;
    let isSending = false;
    let closeTimer = null;
    const dragThreshold = 6;
    const edgePadding = 12;

    const localize = (key, fallback) => {
        const language = document.documentElement.lang === 'en' ? 'en' : 'vi';
        const strings = {
            vi: {
                typing: 'Trợ lý AI đang soạn tin',
                networkError: 'Không thể kết nối trợ lý AI. Vui lòng thử lại.',
                invalidResponse: 'Phản hồi từ trợ lý không hợp lệ.',
                serverError: 'Trợ lý AI hiện không thể phản hồi. Hãy thử lại sau.'
            },
            en: {
                typing: 'AI assistant is typing',
                networkError: 'Could not connect to the AI assistant. Please try again.',
                invalidResponse: 'The assistant returned an invalid response.',
                serverError: 'The AI assistant could not respond right now. Please try again.'
            }
        };
        return strings[language][key] || fallback;
    };

    const scrollToLatest = () => {
        messages.scrollTop = messages.scrollHeight;
    };

    const appendMessage = (text, role, className = '') => {
        const bubble = document.createElement('div');
        bubble.className = `tms-ai-chat-message ${role === 'assistant' ? 'is-assistant' : 'is-user'} ${className}`.trim();
        bubble.textContent = text;
        messages.appendChild(bubble);
        scrollToLatest();
        return bubble;
    };

    const normalizeQuestion = (question) => question.trim().replace(/\s+/g, ' ').toLocaleLowerCase();

    const findPredefinedAnswer = (question) => {
        const language = document.documentElement.lang === 'en' ? 'en' : 'vi';
        const normalizedQuestion = normalizeQuestion(question);
        const match = predefinedFaqs[language].find(([knownQuestion]) =>
            normalizeQuestion(knownQuestion) === normalizedQuestion
        );
        return match ? match[1] : null;
    };

    const appendTypingIndicator = () => {
        const bubble = document.createElement('div');
        bubble.className = 'tms-ai-chat-message is-assistant is-loading';
        bubble.setAttribute('role', 'status');
        bubble.setAttribute('aria-label', localize('typing', 'AI assistant is typing'));
        const dots = document.createElement('span');
        dots.className = 'tms-ai-chat-typing-dots';
        dots.setAttribute('aria-hidden', 'true');
        for (let index = 0; index < 3; index++) {
            const dot = document.createElement('span');
            dot.className = 'tms-ai-chat-typing-dot';
            dots.appendChild(dot);
        }
        bubble.appendChild(dots);
        messages.appendChild(bubble);
        scrollToLatest();
        return bubble;
    };

    const positionPanel = () => {
        if (panel.hidden) return;
        const buttonRect = toggle.getBoundingClientRect();
        const panelRect = panel.getBoundingClientRect();
        const viewportWidth = document.documentElement.clientWidth;
        const viewportHeight = document.documentElement.clientHeight;
        const openToRight = buttonRect.left + buttonRect.width / 2 < viewportWidth / 2;
        let left = openToRight
            ? buttonRect.right + 12
            : buttonRect.left - panelRect.width - 12;
        if (left + panelRect.width > viewportWidth - edgePadding) {
            left = buttonRect.left - panelRect.width - 12;
        } else if (left < edgePadding) {
            left = buttonRect.right + 12;
        }
        left = Math.max(edgePadding, Math.min(left, viewportWidth - panelRect.width - edgePadding));
        const top = Math.max(
            edgePadding,
            Math.min(
                buttonRect.top + buttonRect.height / 2 - panelRect.height / 2,
                viewportHeight - panelRect.height - edgePadding
            )
        );
        panel.style.left = `${left}px`;
        panel.style.top = `${top}px`;
    };

    const openPanel = () => {
        window.clearTimeout(closeTimer);
        panel.hidden = false;
        panel.inert = false;
        panel.setAttribute('aria-hidden', 'false');
        toggle.setAttribute('aria-expanded', 'true');
        requestAnimationFrame(() => {
            positionPanel();
            panel.classList.add('is-open');
            input.focus();
        });
    };

    const closePanel = (returnFocus = false) => {
        panel.classList.remove('is-open');
        panel.inert = true;
        panel.setAttribute('aria-hidden', 'true');
        toggle.setAttribute('aria-expanded', 'false');
        closeTimer = window.setTimeout(() => {
            panel.hidden = true;
        }, 170);
        if (returnFocus) toggle.focus();
    };

    const clampButton = (left, top, snapEdge = false) => {
        const buttonRect = toggle.getBoundingClientRect();
        const width = document.documentElement.clientWidth;
        const height = document.documentElement.clientHeight;
        const nextLeft = snapEdge
            ? (left + buttonRect.width / 2 < width / 2 ? edgePadding : width - buttonRect.width - edgePadding)
            : Math.max(edgePadding, Math.min(left, width - buttonRect.width - edgePadding));
        const nextTop = Math.max(edgePadding, Math.min(top, height - buttonRect.height - edgePadding));
        root.style.left = `${nextLeft}px`;
        root.style.top = `${nextTop}px`;
        root.style.right = 'auto';
        root.style.bottom = 'auto';
        positionPanel();
    };

    toggle.addEventListener('pointerdown', (event) => {
        if (event.button !== undefined && event.button !== 0) return;
        const rect = toggle.getBoundingClientRect();
        pointerStart = {
            id: event.pointerId,
            x: event.clientX,
            y: event.clientY,
            left: rect.left,
            top: rect.top
        };
        moved = false;
        toggle.setPointerCapture(event.pointerId);
    });

    toggle.addEventListener('pointermove', (event) => {
        if (!pointerStart || event.pointerId !== pointerStart.id) return;
        const deltaX = event.clientX - pointerStart.x;
        const deltaY = event.clientY - pointerStart.y;
        if (!moved && Math.hypot(deltaX, deltaY) < dragThreshold) return;
        moved = true;
        event.preventDefault();
        clampButton(pointerStart.left + deltaX, pointerStart.top + deltaY);
    });

    const finishDrag = (event) => {
        if (!pointerStart || event.pointerId !== pointerStart.id) return;
        if (moved) {
            const rect = toggle.getBoundingClientRect();
            clampButton(rect.left, rect.top, true);
            suppressClick = true;
            window.setTimeout(() => { suppressClick = false; }, 0);
        }
        pointerStart = null;
    };
    toggle.addEventListener('pointerup', finishDrag);
    toggle.addEventListener('pointercancel', finishDrag);

    toggle.addEventListener('click', (event) => {
        if (suppressClick) {
            event.preventDefault();
            event.stopPropagation();
            return;
        }
        if (panel.hidden) openPanel();
        else closePanel();
    });
    close.addEventListener('click', () => closePanel(true));
    window.addEventListener('resize', () => {
        if (root.style.left) {
            const rect = toggle.getBoundingClientRect();
            clampButton(rect.left, rect.top);
        }
        positionPanel();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !panel.hidden) closePanel(true);
    });

    const setSending = (sending) => {
        isSending = sending;
        input.disabled = sending;
        send.disabled = sending;
        quickChips.querySelectorAll('button').forEach((chip) => {
            chip.disabled = sending;
        });
    };

    const sendMessage = async (value) => {
        const text = value.trim();
        if (!text || isSending) return;

        errorBox.hidden = true;
        appendMessage(text, 'user');
        input.value = '';
        const predefinedAnswer = findPredefinedAnswer(text);
        if (predefinedAnswer !== null) {
            appendMessage(predefinedAnswer, 'assistant');
            history.push({role: 'user', text}, {role: 'assistant', text: predefinedAnswer});
            if (history.length > 8) history.splice(0, history.length - 8);
            return;
        }

        setSending(true);
        const loadingBubble = appendTypingIndicator();
        const requestHistory = [...history.slice(-8), {role: 'user', text}];

        try {
            const body = new URLSearchParams({
                csrf_token: root.dataset.csrfToken || '',
                locale: document.documentElement.lang === 'en' ? 'en' : 'vi',
                messages: JSON.stringify(requestHistory)
            });
            const response = await fetch(root.dataset.endpoint || 'ai_chat.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body
            });
            const responseText = await response.text();
            let result;
            try {
                result = JSON.parse(responseText);
            } catch {
                throw new Error(localize('invalidResponse', 'The assistant returned an invalid response.'));
            }
            if (!response.ok || result.success !== true || typeof result.reply !== 'string') {
                throw new Error(typeof result.message === 'string'
                    ? result.message
                    : localize('serverError', 'The AI assistant could not respond right now.'));
            }
            loadingBubble.remove();
            appendMessage(result.reply, 'assistant');
            history.push({role: 'user', text}, {role: 'assistant', text: result.reply});
            if (history.length > 8) history.splice(0, history.length - 8);
        } catch (error) {
            loadingBubble.remove();
            errorBox.textContent = error instanceof TypeError
                ? localize('networkError', 'Could not connect to the AI assistant.')
                : (error instanceof Error ? error.message : localize('serverError', 'The AI assistant could not respond.'));
            errorBox.hidden = false;
        } finally {
            setSending(false);
            input.focus();
        }
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        void sendMessage(input.value);
    });

    quickChips.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target.closest('button[data-question-vi][data-question-en]') : null;
        if (!target || isSending) return;
        const question = document.documentElement.lang === 'en'
            ? target.dataset.questionEn
            : target.dataset.questionVi;
        if (question) void sendMessage(question);
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            form.requestSubmit();
        }
    });
})();
