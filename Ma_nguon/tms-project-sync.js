(() => {
    const body = document.body;
    let revision = body.dataset.projectSyncRevision || '';
    let checking = false;
    let notice;

    const hasFocusedEditor = () => {
        const active = document.activeElement;
        return active instanceof HTMLElement && active.matches(
            'input:not([type="hidden"]), textarea, select, [contenteditable="true"]'
        );
    };

    const showReloadNotice = () => {
        if (notice) return;

        notice = document.createElement('aside');
        notice.setAttribute('role', 'status');
        notice.className = 'fixed bottom-4 right-4 z-50 flex max-w-md items-center gap-3 rounded-xl bg-indigo-950 px-4 py-3 text-sm text-white shadow-xl';
        notice.innerHTML = '<span>Có cập nhật dự án hoặc phân công. Hãy tải lại để xem dữ liệu mới.</span>';
        const reloadButton = document.createElement('button');
        reloadButton.type = 'button';
        reloadButton.className = 'shrink-0 rounded-lg bg-white px-3 py-1.5 font-semibold text-indigo-950';
        reloadButton.textContent = 'Tải lại';
        reloadButton.addEventListener('click', () => window.location.reload());
        notice.append(reloadButton);
        document.body.append(notice);
    };

    const checkRevision = async () => {
        if (checking || document.visibilityState !== 'visible') return;
        checking = true;
        try {
            const response = await fetch('api_project_sync_state.php', {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' }
            });
            if (response.status === 401) {
                window.location.reload();
                return;
            }
            if (!response.ok) throw new Error(`Workspace sync returned HTTP ${response.status}`);
            const state = await response.json();
            if (!state.success || typeof state.revision !== 'string') {
                throw new Error('Workspace sync response is invalid');
            }
            if (state.revision !== revision) {
                revision = state.revision;
                if (hasFocusedEditor()) {
                    showReloadNotice();
                } else {
                    window.location.reload();
                }
            }
        } catch (error) {
            console.error('Workspace sync check failed:', error);
        } finally {
            checking = false;
        }
    };

    window.setInterval(checkRevision, 30000);
})();
