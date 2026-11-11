<?php
$aiChatUser = $currentUser ?? ($user ?? null);
if (empty($aiChatUser)) {
    return;
}
require_once __DIR__ . '/../src/Csrf.php';
$aiChatCsrfToken = Csrf::token();
?>
<div
    id="tms-ai-chat-root"
    class="fixed bottom-6 right-6 z-[90]"
    data-endpoint="ai_chat.php"
    data-csrf-token="<?= htmlspecialchars($aiChatCsrfToken, ENT_QUOTES, 'UTF-8') ?>"
>
    <button
        id="tms-ai-chat-toggle"
        class="group relative grid h-14 w-14 place-items-center rounded-full text-white shadow-xl"
        type="button"
        aria-expanded="false"
        aria-controls="tms-ai-chat-panel"
        aria-label="Mở trợ lý AI"
        data-i18n-aria-label="chat.open"
        title="Trợ lý AI"
        data-i18n-title="chat.title"
    >
        <span class="tms-ai-chat-orbit" aria-hidden="true"></span>
        <svg class="tms-ai-chat-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none">
            <path d="M12 3.25 14.4 9.6l6.35 2.4-6.35 2.4L12 20.75 9.6 14.4l-6.35-2.4L9.6 9.6 12 3.25Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
            <path d="m19 2 .8 2.2L22 5l-2.2.8L19 8l-.8-2.2L16 5l2.2-.8L19 2Z" fill="currentColor"/>
        </svg>
        <span class="sr-only" data-i18n="chat.open">Mở trợ lý AI</span>
    </button>
</div>

<section
    id="tms-ai-chat-panel"
    class="fixed z-[91] flex flex-col overflow-hidden rounded-2xl shadow-2xl"
    role="dialog"
    aria-labelledby="tms-ai-chat-title"
    aria-modal="false"
    hidden
>
    <header class="tms-ai-chat-header flex items-center justify-between gap-3 px-4 py-3">
        <div class="flex min-w-0 items-center gap-3">
            <span class="tms-ai-chat-avatar" aria-hidden="true">✦</span>
            <div class="min-w-0">
                <h2 id="tms-ai-chat-title" class="truncate text-sm font-bold" data-i18n="chat.title">Trợ lý AI TMS</h2>
                <p class="tms-ai-chat-subtitle text-xs" data-i18n="chat.online">Hỗ trợ quy trình dịch thuật</p>
            </div>
        </div>
        <button
            id="tms-ai-chat-close"
            class="tms-ai-chat-close grid h-8 w-8 shrink-0 place-items-center rounded-lg"
            type="button"
            aria-label="Đóng cửa sổ chat"
            data-i18n-aria-label="chat.close"
        >×</button>
    </header>
    <div id="tms-ai-chat-messages" class="tms-ai-chat-messages flex-1 overflow-y-auto p-4" aria-live="polite" aria-relevant="additions text">
        <div class="tms-ai-chat-greeting">
            <span class="tms-ai-chat-greeting-icon" aria-hidden="true">✦</span>
            <div>
                <h3 data-i18n="chat.greeting_title">Xin chào, mình là trợ lý AI</h3>
                <p data-i18n="chat.welcome">Mình có thể hỗ trợ bạn về quy trình dịch thuật và cách sử dụng TMS. Bạn muốn bắt đầu từ đâu?</p>
            </div>
        </div>
        <div class="tms-ai-chat-quick-heading" data-i18n="chat.quick_heading">Gợi ý nhanh</div>
        <div id="tms-ai-chat-quick-chips" class="tms-ai-chat-quick-chips">
            <button type="button" class="tms-ai-chat-chip" data-i18n="chat.quick_status"
                data-question-vi="📊 Trạng thái dịch: hướng dẫn tôi xem tiến độ, số câu đã dịch và số câu còn lại trong dự án."
                data-question-en="📊 Translation status: show me how to check progress, translated sentences, and what remains in the project.">📊 Trạng thái dịch</button>
            <button type="button" class="tms-ai-chat-chip" data-i18n="chat.quick_sync"
                data-question-vi="🔄 Lỗi đồng bộ API: giúp tôi kiểm tra các nguyên nhân thường gặp và cách khắc phục."
                data-question-en="🔄 API sync error: help me check common causes and troubleshooting steps.">🔄 Lỗi đồng bộ API</button>
            <button type="button" class="tms-ai-chat-chip" data-i18n="chat.quick_shortcuts"
                data-question-vi="💡 Hướng dẫn phím tắt: cho tôi biết các cách thao tác nhanh khi dịch và rà soát."
                data-question-en="💡 Keyboard shortcuts: tell me ways to work faster while translating and reviewing.">💡 Hướng dẫn phím tắt</button>
            <button type="button" class="tms-ai-chat-chip" data-i18n="chat.quick_projects"
                data-question-vi="⚙️ Quản lý dự án: hướng dẫn tôi cách tổ chức và quản lý dự án dịch thuật."
                data-question-en="⚙️ Project management: guide me through organizing and managing a translation project.">⚙️ Quản lý dự án</button>
        </div>
    </div>
    <p id="tms-ai-chat-error" class="tms-ai-chat-error mx-3 mb-2 rounded-lg px-3 py-2 text-xs" role="status" hidden></p>
    <form id="tms-ai-chat-form" class="tms-ai-chat-form flex items-end gap-2 p-3">
        <label class="sr-only" for="tms-ai-chat-input" data-i18n="chat.input_label">Tin nhắn</label>
        <textarea
            id="tms-ai-chat-input"
            class="tms-ai-chat-input min-h-11 flex-1 resize-none rounded-xl px-3 py-2 text-sm"
            name="message"
            maxlength="2000"
            rows="1"
            required
            placeholder="Nhập câu hỏi..."
            data-i18n-placeholder="chat.placeholder"
        ></textarea>
        <button
            id="tms-ai-chat-send"
            class="tms-ai-chat-send grid h-11 w-11 shrink-0 place-items-center rounded-xl"
            type="submit"
            aria-label="Gửi tin nhắn"
            data-i18n-aria-label="chat.send"
        >
            <svg aria-hidden="true" viewBox="0 0 20 20" fill="none">
                <path d="M3 10h13m-5-5 5 5-5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </button>
    </form>
</section>
<script src="tms-ai-chat.js" defer></script>
