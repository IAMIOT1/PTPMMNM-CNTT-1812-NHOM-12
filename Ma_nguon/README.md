# Lab 05 — Lưu, truy vấn và thống kê đăng ký với postgresql

Mã mẫu cho phần hướng dẫn và mã khởi đầu cho bài tự làm.

## Mở bài

Mở terminal Ubuntu/WSL tại thư mục chứa `lab.sh`. Dùng PHP từ 8.1 và Git. Hướng dẫn chi tiết, sơ đồ, đoạn mã cần sửa, dữ liệu đối chiếu và rubric nằm trong tài liệu của bài lab.

```bash
bash lab.sh check
bash lab.sh run
```

Mở http://127.0.0.1:8105. Giữ terminal máy chủ hoạt động; dùng terminal thứ hai để kiểm thử. Nhấn Ctrl+C để dừng.

Lab05 cần PostgreSQL và pgsql. Thực hiện mục Chuẩn bị riêng cho Lab 05, chạy `bash lab.sh db-config`, kiểm tra kết nối và tạo schema trước khi chạy Web hoặc test.

## Kiểm tra bài

```bash
bash lab.sh test
bash lab.sh extend
```

`test` kiểm tra phần mẫu; `extend` kiểm tra phần tự làm. Các vị trí TODO là nhiệm vụ cần hoàn thiện; không sửa test để bỏ qua lỗi.

Kết quả mở rộng cần đạt: `TESTS=4 FAILED=0`. Có luồng Form lưu đăng ký thật, chống trùng theo sự kiện–email, tra cứu danh sách và thống kê cả sự kiện chưa có người đăng ký.

## Tệp nộp

Giữ mã nguồn, dữ liệu mẫu và kiểm thử. Ghi lại lệnh chạy trong README. Không đưa `.local`, mật khẩu hoặc dữ liệu cá nhân thật vào kho mã và gói nộp. Nộp đúng commit theo yêu cầu của bài; nếu dùng Actions, đính kèm lần chạy tương ứng.


## Phần trình bày giao diện

Lab này giữ CSS cục bộ tại `public/assets/css/app.css`. HTML nằm trong `public/`; các lab có nhiều trang dùng `templates/header.php` và `templates/footer.php`. Có thể lần từ các lớp `form-field`, `event-item`, `error-summary` sang CSS để đọc cách trình bày; Lab01 chỉ dùng phần trang giới thiệu.

Các lớp, nhãn và bố cục chỉ phục vụ hiển thị. Giữ nguyên khối PHP, GET/POST, tên trường, giá trị option, hidden input và thuộc tính kiểm tra đầu vào khi thực hành. Không cần JavaScript, thư viện hoặc bước build mới để chạy giao diện.


## Giao diện Material 3

Màu và kiểu chữ nằm trong `public/assets/css/tokens.css`; bố cục trong `app.css`. Không có bước build hoặc thư viện mới.

Ba lớp được tách rõ:

- `/`: sự kiện gốc của lab; tập kết quả, bộ lọc và form nghiệp vụ giữ nguyên.
- `/event.php?id=1`: chi tiết chỉ đọc, bổ sung metadata hư cấu từ `data/event-content.php`. CTA mở `/register.php` không kèm tham số mới.
- `/ui-demo.php`: sáu sự kiện hư cấu, ID `demo-...` từ `data/ui-demo-events.php`. `/ticket-preview.php?id=demo-open-source` chỉ thử vé/ghế trong bộ nhớ trang, không có form hoặc API ghi dữ liệu.

Khu thử có 96 ghế cố định, 68 khả dụng (16 VIP và 52 Tiêu chuẩn). Hai vé VIP mẫu 350.000 đồng cho tổng 700.000 đồng. Đổi hạng, đổi sự kiện hoặc tải lại sẽ bỏ lựa chọn không phù hợp; không có trạng thái đã giữ chỗ.

Ảnh SVG tự tạo, lưu cục bộ và ghi quyền CC0 trong `public/assets/images/LICENSE.txt`. `presentation.js` chỉ xử lý ảnh lỗi; `ticket-preview.js` chỉ chạy trên trang thử. Khi tắt JavaScript, vẫn đọc được nội dung và dùng form lab như cũ; khu chọn thử được khóa kèm giải thích.

Không đưa ID demo, vé hoặc ghế vào form, Session, Cookie, CSDL hoặc test cũ. TODO sinh viên và đáp án giữ nguyên khác biệt. Không có backend bán vé, thanh toán hoặc gửi email.

## TMS workflow setup

For an existing TMS database, run the migrations in this order after the base schema and existing project/workflow migrations:

```bash
php migrate_tms_workflow.php
php migrate_translation_features.php
php migrate_account_preferences.php
php migrate_tms_core.php
php migrate_live_preview.php
php migrate_project_roles.php
php migrate_system_administration.php
php migrate_admin_user_roles.php
php migrate_audit_log_ip.php
php migrate_support_tickets.php
php migrate_locales.php
php migrate_locale_catalog.php
php migrate_project_locales.php
php migrate_sequential_ids.php
php migrate_enterprise_features.php
php migrate_tm_repository_global_uniqueness.php
php migrate_performance_locking.php
```

The workflow migration creates `release_sync_history` and backfills existing version locks. The core migration adds source-version diffs and freezing, and removes legacy per-key translation assignments. All project members can see the complete translation text; Translators can edit every key in their project, while Reviewers and Team Leads can edit and review all keys. Member management, source import, freeze and release actions remain role-restricted. A frozen source version is read-only; publishing a release from the workspace requires freezing it first. Release history and preview are available to project members from the project page, with separate version-lock and API-sync tabs. Successful API syncs store the sent JSON and the prior endpoint state, forming a project-and-endpoint rollback chain. The first sync requires the endpoint to return a valid JSON snapshot via GET; rollback sends the previous snapshot using PUT or PATCH before marking history as removed. Rollbacks must be applied newest-first to keep the chain consistent. Bearer tokens are never stored and must be re-entered for rollback.

The locale catalog migration adds all current ISO 639-1 language codes to the `locales` table without replacing existing locale names. Run it after `migrate_locales.php` and before `migrate_project_locales.php`, so every language offered by the workspace selector satisfies translation and project-locale foreign keys. The project-locale migration enables the deployed-language tabs (Vietnamese and English by default) for existing projects. Run the sequential-ID migration after existing data migrations; it remaps legacy user and project IDs from `10000001`, cascades their dependent foreign keys, and keeps the ID sequences above that range. It is safe to run again after the remap.

The enterprise migration enables project and global Translation Memory/Glossary entries. Run `migrate_tm_repository_global_uniqueness.php` after it to add the global TM `updated_at` column, remove duplicate global TM keys while retaining the newest row, and create the partial unique index required for atomic repository imports. The workspace suggestion endpoint applies exact/fuzzy TM matches, glossary replacements, then a deterministic local fallback. Team Leads manage locale-specific project glossary terms from the compact workspace panel; create, edit, and delete requests use CSRF-protected JSON actions scoped to the current project and locale. Source imports accept JSON, CSV (`key,source_text`) and XLSX with the same two-column shape.

## TM Repository & CSV Manager

The authenticated `tm_repository.php` page stores approved project translation pairs outside the public web root under `storage/tm-repository/`. Project CSV files require the exact columns `locale,source_text,target_text` in that order; comma and semicolon delimiters are detected automatically. Team Leads and system administrators can upload or delete CSV files; all authenticated users can download them. Uploads accept CSV files up to 5 MB, return JSON to the upload form, and update the filesystem-backed file list immediately. CSV rows are imported into global Translation Memory transactionally; a failed database import removes the newly stored file. The storage directory must be writable by the PHP process. Approving a translation automatically refreshes the corresponding project CSV.

Source imports support JSON, CSV, PO/Gettext, XLIFF, Android XML, and XLSX (where the PHP ZIP/XML extensions are enabled). Translation package exports support JSON, CSV, PO, XLIFF, and Android XML. New comments notify other members of the project; assignments and rejected/returned translations use the existing notification center in the header. Run `php migrate_account_preferences.php` after `migrate_translation_features.php` to enable per-user project-invitation and comment/review notification preferences. Notification preferences default to enabled. The account menu links to recent activity, notification settings, and Vietnamese/English language selection.

Global localization is loaded by `tms-i18n.js` from `lang/vi.php` and `lang/en.php` on the workspace, project translation, TM repository, release, comments, profile, administration, login, and registration pages. Language selection is stored in `localStorage` under `tms-language` and persists across page navigation. Static labels use `data-i18n` (including title, placeholder, and accessibility-label attributes); the shared DOM observer also marks known dictionary labels added dynamically, while user-provided translation, project, glossary, and release content is excluded from automatic rewriting.

The performance migration adds server-side key pagination, short-lived translation locks, and exact/fuzzy TM indexes. Run it after the enterprise migration.

## Content ingestion, preview, and sync

The translation table supports selecting editable rows individually or with the header checkbox. When every editable row on the current page is selected, users can extend the selection to all records matching the active search and status filters across the current source version. Global selection applies only to bulk status changes; translation text is never inferred for unloaded pages, and rows locked by another editor are skipped. Individual row saves and bulk updates include each row's `updated_at` baseline; the server locks and compares rows inside the write transaction, returning HTTP 409 with the current text, editor, and timestamp instead of overwriting stale work. The conflict dialog compares the submitted and current text and can refresh the affected row; bulk updates remain atomic. Translation suggestions are requested only after user input (not on focus), and lock/suggestion endpoints return JSON independently of the audit-export route.

On the project dashboard, Team Leads can import a local JSON file or a public HTML page/JSON endpoint from the unified source-import card. JSON objects are flattened into translation keys; HTML text nodes are recursively collected into normalized, deduplicated `page.text_NNNN` keys, including navigation, interactive button labels and submit/button input values, while ignoring scripts, styles, metadata, comments, and hidden content. Both input methods use the selected version and refresh dashboard counts from the imported source version. Before import, the dashboard checks whether the current version has translations that have not been frozen and exported; if so, it asks for explicit confirmation, offers a way to return to export first, and provides a **Publish now & Import** action that freezes the current version, creates the release snapshot for the selected locale, then continues the import automatically. The server repeats this check before replacing any version data. Re-importing an existing version is a complete replacement: old strings and related translations are deleted before new key paths are inserted, so translations must be reviewed again. In the Publish panel, Reviewers and Team Leads can open Sync Back, configure a destination endpoint, and POST or PUT the approved translations for the selected version and locale, optionally using a Bearer token. Requests do not follow redirects; destinations resolve to a pinned public IPv4 address, source responses are limited to 2 MB, and sync requires HTTPS. The Live Preview switches between unsaved draft text and saved approved text; existing JSON export and Git push behavior elsewhere in the TMS is unchanged.

When importing from a URL/API, the URL is stored on that source version in `source_versions.source_url`. HTML imports also retain the fetched page in `source_versions.source_html` for the Live Preview; run `php migrate_live_preview.php` on existing databases. Each preview open fetches the selected version and locale from the server with caching disabled. HTML snapshots are rendered in a sandboxed iframe with source scripts, event handlers, and embedded frames removed; static HTML/CSS and permitted remote assets are retained, but JavaScript-rendered, personalized, or later-changing content cannot be reproduced exactly. Translations are applied directly to matching snapshot text nodes before the HTML is assigned to the iframe, without an iframe script or message round-trip. This avoids embedding the live page itself, so framing restrictions and CORS do not apply. JSON/file imports without an HTML snapshot show the translated strings directly. Draft mode reflects current editor contents; Approved mode shows approved translations and falls back to source text for other statuses. Every project member can run AI Translate from the language heading or per-row action; the server uses `AI_API_KEY` (with `GEMINI_API_KEY` retained as a backward-compatible alias) from its environment or `.env`, and never sends the token to the browser. Frozen versions cannot be AI-translated.

AI Translate uses the server-side `AI_API_KEY` from the process environment or `.env`. It runs through sequential AJAX batches of up to eight strings, waits 1.5 seconds between requests, and times out an individual AJAX request after 60 seconds. Each batch gets up to two retries (three total attempts) for network failures, timeout, and transient HTTP/AI errors; retry details including the HTTP status are logged in the browser console. Each translated string is persisted immediately, and the workflow can continue from the remaining untranslated strings after retries are exhausted. The per-row AI action posts `source_text` and `string_id` to a CSRF-protected endpoint, which verifies the source belongs to the current version before calling the external provider and returns `target_text` without exposing the API key. Existing non-empty translations and approved strings are not overwritten by batch mode. Gemini calls `https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent` using `gemini-3.8-flash` first, then `gemini-3.6-flash` and `gemini-3.5-flash-lite` as fallbacks. Each model is retried up to two times with short exponential backoff after overload / HTTP 503; an HTTP 404 indicating an unknown model immediately tries the next fallback. Other HTTP errors are returned without retrying.

Password recovery uses one-time, expiring reset links rather than emailing passwords. Run `php migrate_password_reset_tokens.php`, then configure `TMS_PUBLIC_URL` and `TMS_MAIL_FROM` in the server environment; SMTP credentials must remain outside the repository.

Email verification uses `verification_code` and `is_verified`. Run `php migrate_email_verification.php`; configure the server mail transport (or `TMS_MAIL_FROM` for the configured mail transport) before enabling registrations. Existing accounts are treated as verified by the migration, while new registrations must verify their email before login.

Registration verification mail uses Gmail SMTP with STARTTLS. Configure `TMS_SMTP_HOST=smtp.gmail.com`, `TMS_SMTP_PORT=587`, `TMS_SMTP_USERNAME`, `TMS_SMTP_PASSWORD`, and `TMS_MAIL_FROM` in the process environment. SMTP failures are logged server-side with the provider response in `SmtpMailer::$errorInfo`; credentials are never committed or shown to users.
MyMemory dùng cURL qua `https://api.mymemory.translated.net/get`, không phụ thuộc Composer, `stichoza/google-translate-php` hoặc `mbstring`. Ngôn ngữ nguồn mặc định lấy từ `TMS_SOURCE_LOCALE` (mặc định `vi`), ngôn ngữ đích theo locale đang chọn; request gửi `q`, `langpair=source|target` và tùy chọn `de` từ `MYMEMORY_EMAIL`. Response lấy từ `responseData.translatedText` sau khi kiểm tra `responseStatus`. Popup tải tiến độ từ database trước khi chạy và hiển thị số câu đã dịch/tổng số cùng progress bar. Frontend chia các câu chưa dịch thành các gói cân bằng từ 10–20 câu (gói cuối có thể nhỏ hơn 10 nếu tổng số câu còn lại ít hơn 10), sau đó điều phối bằng pool tối đa 5 worker; mỗi worker gửi request AJAX, nhận kết quả rồi bốc gói tiếp theo với khoảng nghỉ 250 ms. Server xác thực từng câu thuộc phiên bản nguồn hiện tại, dịch tuần tự trong từng gói, kiểm tra placeholder và lưu ngay từng bản nháp hợp lệ mà không ghi đè bản dịch không rỗng. MyMemory dùng User-Agent trình duyệt và chờ ít nhất 1 giây giữa các request trong mỗi worker, đồng thời retry exponential backoff với HTTP 429/5xx. Progress bar được cập nhật theo số bản dịch thành công đã ghi nhận, không đếm trùng khi phản hồi các worker đến sai thứ tự. Khi một gói lỗi, giao diện báo chi tiết và tiếp tục các worker khác; nếu bị giới hạn tần suất, pool không nhận thêm gói mới và có thể tiếp tục sau. Lỗi mạng/HTTP/JSON được ghi log an toàn.

Live Preview ưu tiên `source_html` snapshot thực tế của phiên bản trong một iframe sandbox độc lập. Snapshot được bổ sung thẻ `base` tuyệt đối từ `source_url` (hoặc URL hiện tại nếu snapshot không có URL) để CSS, hình ảnh và font tương đối tải đúng; script/handler nguy hiểm bị loại bỏ. Bản dịch được áp dụng trực tiếp vào text node trước khi gán snapshot vào iframe; không dùng script bên trong iframe hoặc `postMessage`. Bản dịch không thay đổi thẻ HTML, thuộc tính hoặc cấu trúc bố cục. Phiên bản không có snapshot hiển thị danh sách chuỗi theo đúng chế độ nháp/đã duyệt.

Run `php tests/remote_content.php` to test ingestion parsing and private-network URL rejection.

## System administration

The system-administration migration adds an active/locked account status and actor/target references for administrative audit events. Run `php migrate_admin_user_roles.php` after it to normalize global roles to `admin` and `user`; project-level membership remains in `project_members`, with its separate `Team Lead`, `Translator`, and `Reviewer` roles.

Run `php migrate_audit_log_ip.php` to add client IP storage and an index for audit lookups. The Admin page records user-management actions and successful/failed approved-translation syncs, then displays actor, action, target/details, IP address, and timestamp with date-range filters and pagination.

Run `php migrate_support_tickets.php` to enable support requests and the optional `image_path` attachment field. Users can attach JPEG, PNG, GIF, or WebP screenshots up to 5 MB and review them in their ticket history; admins can review and resolve pending tickets, view attached screenshots, and see the pending count on the administration page.

Only active users with the global `admin` role can open `admin_users.php`. The page is limited to user management: admins can create accounts, edit usernames/emails/roles, and delete accounts. The global role selector contains only `admin` and `user`; the final active administrator cannot be demoted or deleted, and admins cannot demote or delete their own account. The table displays account status, while project-level roles remain separate from system roles.

No privileged account is created automatically. To bootstrap administration, first register a normal user, then have a database operator promote that account using its verified email, for example:

```sql
UPDATE users
SET role = 'admin', is_active = TRUE
WHERE lower(email) = lower('verified-admin@example.com');
```

The account can then sign in and use **🛡️ Quản trị hệ thống**. Never promote an account based solely on unverified user input.
