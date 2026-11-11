(() => {
    const formSelector = '[data-sync-back-form]';

    const getToastContainer = () => {
        let container = document.getElementById('sync-back-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'sync-back-toast-container';
            container.className = 'tms-toast-container';
            container.setAttribute('aria-live', 'polite');
            container.setAttribute('aria-atomic', 'false');
            document.body.append(container);
        }
        return container;
    };

    const showToast = (message, type) => {
        const toast = document.createElement('div');
        toast.className = `tms-toast tms-toast-${type}`;
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
        toast.textContent = message;
        getToastContainer().append(toast);
        window.setTimeout(() => {
            toast.classList.add('is-leaving');
            window.setTimeout(() => toast.remove(), 220);
        }, 6000);
    };

    const isTmsWebsiteEndpoint = (endpoint) => {
        const hostname = endpoint.hostname.toLowerCase().replace(/\.$/, '');
        const currentHostname = window.location.hostname.toLowerCase().replace(/\.$/, '');
        return hostname === currentHostname || hostname === 'netlify.app' || hostname.endsWith('.netlify.app');
    };

    const isHtmlResponse = (contentType, body) =>
        /^text\/html(?:\s*;|$)/i.test(contentType) ||
        /^\s*<(?:!doctype\s+html|html)\b/i.test(body);

    const submitSyncForm = async (form, event) => {
        event.preventDefault();
        const urlInput = form.querySelector('#sync-back-url, #sync-url');
        const methodInput = form.elements.namedItem('sync_method');
        const tokenInput = form.elements.namedItem('sync_token');
        const submitButton = form.querySelector('button[type="submit"]');
        if (!(urlInput instanceof HTMLInputElement)) {
            showToast('Vui lòng nhập đầy đủ URL Endpoint API hợp lệ!', 'error');
            console.error('[TMS Sync Back] Endpoint input was not found in the Sync Back form.', {
                form: form.id || '(không có id)'
            });
            return;
        }

        const endpoint = urlInput.value.trim();
        if (!endpoint || endpoint.includes('[object')) {
            urlInput.setCustomValidity('Vui lòng nhập đầy đủ URL Endpoint API hợp lệ!');
            urlInput.reportValidity();
            showToast('Vui lòng nhập đầy đủ URL Endpoint API hợp lệ!', 'error');
            return;
        }
        urlInput.setCustomValidity('');

        let parsedEndpoint;
        try {
            parsedEndpoint = new URL(endpoint);
        } catch {
            parsedEndpoint = null;
        }

        if (
            !/^https?:\/\//i.test(endpoint) ||
            endpoint.length > 2048 ||
            !parsedEndpoint ||
            !['http:', 'https:'].includes(parsedEndpoint.protocol) ||
            parsedEndpoint.hostname === '' ||
            parsedEndpoint.username !== '' ||
            parsedEndpoint.password !== ''
        ) {
            urlInput.setCustomValidity('Vui lòng nhập URL endpoint hợp lệ bắt đầu bằng http:// hoặc https://.');
            urlInput.reportValidity();
            showToast('URL endpoint không hợp lệ. URL phải bắt đầu bằng http:// hoặc https://.', 'error');
            return;
        }
        urlInput.setCustomValidity('');

        if (isTmsWebsiteEndpoint(parsedEndpoint)) {
            const message = 'Lỗi: Không thể trỏ API Endpoint về chính trang web TMS. Vui lòng sử dụng đường dẫn API JSON hợp lệ (như npoint.io).';
            urlInput.setCustomValidity(message);
            urlInput.reportValidity();
            console.error('[TMS Sync Back] Rejected a website URL as the API endpoint.', {
                endpoint: parsedEndpoint.href,
                currentUrl: window.location.href
            });
            showToast(message, 'error');
            return;
        }

        const method = methodInput.value.toUpperCase();
        if (!['POST', 'PUT'].includes(method)) {
            showToast('Phương thức đồng bộ chỉ được là POST hoặc PUT.', 'error');
            return;
        }

        const submitLabel = submitButton.querySelector('[data-i18n]');
        const originalLabel = submitLabel?.textContent || submitButton.textContent;
        const actionAttribute = form.getAttribute('action')?.trim() || window.location.href;
        let requestUrl;
        try {
            requestUrl = new URL(actionAttribute, window.location.href);
        } catch (error) {
            showToast('Không thể xác định địa chỉ xử lý Sync Back hợp lệ. Vui lòng tải lại trang.', 'error');
            console.error('[TMS Sync Back] Invalid same-origin proxy URL.', {actionAttribute, error});
            return;
        }
        if (requestUrl.origin !== window.location.origin) {
            showToast('Không thể xác định địa chỉ xử lý Sync Back hợp lệ. Vui lòng tải lại trang.', 'error');
            console.error('[TMS Sync Back] Sync Back proxy must be same-origin.', {
                endpoint,
                requestUrl: requestUrl.href
            });
            return;
        }

        submitButton.disabled = true;
        if (submitLabel) submitLabel.textContent = 'Đang đồng bộ...';

        try {
            console.log("Endpoint đang gọi:", endpoint);
            const requestPayload = Object.fromEntries(new FormData(form).entries());
            const response = await fetch(requestUrl.href, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-TMS-Sync-Back': '1'
                },
                body: JSON.stringify(requestPayload)
            });
            const responseText = await response.text();
            const responseContentType = response.headers.get('content-type') || '';

            if (isHtmlResponse(responseContentType, responseText)) {
                const message = `Máy chủ trả về trang HTML (HTTP ${response.status}). Bạn đang gọi nhầm vào một trang web thay vì API JSON.`;
                console.error('[TMS Sync Back] HTML response received for the API request.', {
                    endpoint: parsedEndpoint.href,
                    status: response.status,
                    contentType: responseContentType,
                    responseUrl: response.url,
                    body: responseText.slice(0, 1000)
                });
                throw new Error(message);
            }

            if (!/^application\/(?:[a-z0-9!#$&^_.+-]+\+)?json(?:\s*;|$)/i.test(responseContentType)) {
                console.error('[TMS Sync Back] Backend response did not declare JSON.', {
                    status: response.status,
                    contentType: responseContentType,
                    body: responseText.slice(0, 1000)
                });
                throw new Error(`Máy chủ TMS trả về định dạng không phải JSON (HTTP ${response.status}).`);
            }

            let result;
            try {
                result = JSON.parse(responseText);
            } catch (parseError) {
                console.error('[TMS Sync Back] Could not parse backend response.', {
                    status: response.status,
                    contentType: response.headers.get('content-type'),
                    body: responseText.slice(0, 1000),
                    error: parseError
                });
                throw new Error(`Phản hồi từ máy chủ TMS không hợp lệ (HTTP ${response.status}).`);
            }

            if (!response.ok || result.success !== true) {
                const remoteDetail = typeof result.detail === 'string' ? result.detail : '';
                if (
                    /^text\/html(?:\s*;|$)/i.test(result.remote_content_type || '') ||
                    /^\s*<(?:!doctype\s+html|html)\b/i.test(remoteDetail)
                ) {
                    const message = 'Máy chủ đích trả về trang HTML. Bạn đang gọi nhầm vào một trang web thay vì API JSON.';
                    console.error('[TMS Sync Back] Destination endpoint returned HTML instead of JSON.', {
                        endpoint: parsedEndpoint.href,
                        status: response.status,
                        detail: remoteDetail.slice(0, 1000)
                    });
                    throw new Error(`${message}${remoteDetail ? ` Chi tiết: ${remoteDetail}` : ''}`);
                }
                const detail = typeof result.detail === 'string' && result.detail.trim() !== ''
                    ? ` Chi tiết từ máy chủ đích: ${result.detail.trim()}`
                    : '';
                throw new Error(`${result.message || `Đồng bộ thất bại (HTTP ${response.status}).`}${detail}`);
            }

            showToast(result.message || 'Đã đồng bộ bản dịch đã duyệt thành công.', 'success');
            tokenInput.value = '';
            form.closest('dialog')?.close();
        } catch (error) {
            console.error('[TMS Sync Back] Request failed.', error);
            showToast(error instanceof Error ? error.message : 'Không thể đồng bộ bản dịch. Vui lòng thử lại.', 'error');
        } finally {
            submitButton.disabled = false;
            if (submitLabel) submitLabel.textContent = originalLabel;
        }
    };

    document.querySelectorAll(formSelector).forEach((form) => {
        form.addEventListener('submit', (event) => {
            void submitSyncForm(form, event);
        });
    });
})();
