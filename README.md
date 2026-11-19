# 🌐 Enterprise TMS — Hệ thống Quản lý Bản dịch và Phát hành Gói ngôn ngữ cho Phần mềm

> **Đề tài Bài tập lớn — Học phần: Phát triển Phần mềm Mã nguồn mở**
> 
> **Trường Đại học Đại Nam — Khoa Công nghệ Thông tin**
> 
> **Nhóm thực hiện:** Nhóm 12 (Nguyễn Lâm Tới, Nguyễn Duy Doanh, Bạch Thị Thanh Loan)

---

## 🔗 Trải nghiệm trực tuyến (Live Demo)

Hệ thống đã được triển khai trực tuyến trên nền tảng đám mây Render. Bạn có thể truy cập và trải nghiệm ngay tại đường dẫn sau:

* **Link truy cập:** [https://enterprise-tms.onrender.com/dang_nhap.php?next=%2Findex.php#support-requests](https://enterprise-tms.onrender.com/dang_nhap.php?next=%2Findex.php#support-requests)

### 🔑 Tài khoản trải nghiệm mẫu

| Vai trò | Tên đăng nhập | Mật khẩu | Ghi chú |
| :--- | :--- | :--- | :--- |
| **Quản trị viên (Admin)** | `admin` | `admin123` | Quản lý toàn hệ thống, phân quyền người dùng, giám sát dự án và kiểm toán. |
| **Người dùng (User)** | `doanh` | `doanh123` | Tài khoản thành viên trải nghiệm sẵn (hoặc bạn có thể tự đăng ký tài khoản mới trực tiếp trên trang). |

---

## 📖 1. Giới thiệu tổng quan

**Enterprise TMS (Translation Management System)** là nền tảng web tập trung hỗ trợ số hóa toàn diện quy trình bản địa hóa phần mềm (*Internationalization - i18n* & *Localization - l10n*). Hệ thống giải quyết triệt để các hạn chế của quy trình dịch thuật thủ công (phân mảnh tệp JSON/Excel, xung đột phiên bản, thiếu nhất quán thuật ngữ) bằng việc cung cấp một không gian làm việc hợp nhất cho **Quản trị viên (Admin)**, **Trưởng nhóm dự án (Team Lead)**, **Biên dịch viên (Translator)** và **Kiểm duyệt viên (Reviewer)**.

### ✨ Tính năng nổi bật
* **Quản lý chuỗi nguồn theo phiên bản (`Source Versioning`):** Tự động làm phẳng (*flatten*) các tệp JSON phân cấp đa tầng, hỗ trợ đa định dạng (`.json`, `.csv`, `.xlsx`, `.po`, `.xliff`, `.xml`) hoặc thu thập trực tiếp từ URL; tự động phát hiện biến động (`added`, `modified`, `deleted`) giữa các phiên bản.
* **Không gian biên dịch thông minh & Live Preview:** Giao diện đối chiếu song ngữ trực quan, tích hợp xem trước giao diện thực tế theo thời gian thực thông qua `window.postMessage()`.
* **Công cụ hỗ trợ dịch thuật (CAT Tools):**
  * **Bộ nhớ dịch (`Translation Memory - TM`):** Tự động lưu trữ và gợi ý tái sử dụng bản dịch dựa trên khớp chính xác 100% hoặc tìm kiếm mờ (*Fuzzy Search*) với tiện ích `pg_trgm` của PostgreSQL.
  * **Từ điển thuật ngữ (`Glossary / Termbase`):** Chuẩn hóa thuật ngữ chuyên ngành bắt buộc trong toàn dự án.
  * **Trợ lý ảo & Dịch máy AI:** Tích hợp dịch tự động qua API (MyMemory / AI Translator) và khung chat hỗ trợ biên dịch viên.
* **Kiểm soát chất lượng & Cộng tác đồng thời:**
  * Cơ chế khóa bản ghi tạm thời (`translation_locks`) kết hợp **Khóa lạc quan (`Optimistic Locking`)** chống ghi đè dữ liệu.
  * Tự động kiểm tra tính toàn vẹn của biến giữ chỗ (`Variable Validator` cho `{var}`, `%s`, `{{count}}`).
  * Luồng phê duyệt/từ chối bản dịch, lưu vết lịch sử chỉnh sửa và thảo luận trực tiếp theo từng chuỗi.
* **Đóng băng & Phát hành gói ngôn ngữ (`Release Management`):** Khóa cứng dữ liệu bằng `Trigger` ở mức CSDL (`Freeze`), tạo bản chụp phát hành bất biến (`Release Snapshots`), xuất gói `JSON`/`ZIP` hoặc đẩy đồng bộ qua API có hỗ trợ `Rollback`.
* **Hỗ trợ Progressive Web App (PWA):** Cho phép cài đặt trực tiếp hệ thống lên máy tính/điện thoại như một ứng dụng độc lập, hỗ trợ bộ nhớ đệm ngoại tuyến (`Service Worker`).

---

## 🛠️ 2. Công nghệ sử dụng

* **Backend & API:** PHP 8.x (Kiến trúc hướng đối tượng OOP, Front Controller)
* **Frontend (Hybrid UI):**
  * Giao diện nghiệp vụ & Live Preview: PHP Server-Side Rendering + Tailwind CSS + Vanilla JavaScript (ES6+)
  * Bảng điều khiển quản trị hiện đại (SPA): React 18, TypeScript, Vite, Tailwind CSS
* **Cơ sở dữ liệu:** PostgreSQL 16 (17 bảng quan hệ, tiện ích `pg_trgm`, `Trigger` bảo vệ tính bất biến dữ liệu)
* **Hạ tầng & Triển khai:** Docker, Apache Web Server (`mod_rewrite`), triển khai thực tế trên Render Cloud.

---

## 📂 3. Cấu trúc thư mục dự án

```text
PTPMMNM-CNTT-1812-NHOM-12/
├── Dockerfile                      # Cấu hình đóng gói container tổng thể
├── README                          # Hướng dẫn 
├── Ma_nguon/src/
├── [Nhóm 1: Hạ tầng, Kết nối & Bảo mật]
├── Database.php                    # Quản lý kết nối PostgreSQL & truy vấn tham số hóa
├── EnvLoader.php                   # Đọc và nạp biến môi trường từ tệp .env
├── SessionManager.php              # Quản lý vòng đời phiên làm việc (Session) an toàn
├── AuthManager.php                 # Xác thực đăng nhập, đăng ký, băm mật khẩu & OTP
├── PasswordResetManager.php        # Xử lý luồng tạo token và đặt lại mật khẩu
├── Csrf.php                        # Sinh và xác thực mã chống giả mạo yêu cầu (CSRF)
├── RateLimiter.php                 # Giới hạn tần suất gửi yêu cầu (chống Brute-force/Spam)
├── Validator.php                   # Bộ kiểm tra tính hợp lệ dữ liệu đầu vào (Input Validation)
├── ProjectAccess.php               # Kiểm soát phân quyền truy cập theo vai trò (RBAC)
│
├── [Nhóm 2: Quản lý Dự án, Phiên bản & Ngôn ngữ]
├── ProjectManager.php              # Nghiệp vụ tạo, cập nhật, xóa và cấu hình dự án
├── MemberManager.php               # Quản lý thành viên tham gia và gán vai trò trong dự án
├── LocaleManager.php               # Quản lý danh mục ngôn ngữ chuẩn ISO 639-1
├── VersionManager.php              # Quản lý các phiên bản gói nguồn (source_versions)
├── VersionDiffEngine.php           # Thuật toán so sánh biến động (Added/Modified/Deleted)
│
├── [Nhóm 3: Phân tích Nguồn & Xử lý Chuỗi]
├── SourceImportParser.php          # Bộ điều phối nhập liệu đa định dạng tệp nguồn
├── JsonParser.php                  # Làm phẳng (flatten) & tái tạo cây JSON đa cấp
├── CsvXlsxParser.php               # Đọc và trích xuất chuỗi từ tệp bảng tính CSV/Excel
├── PoXliffParser.php               # Phân tích chuẩn dịch thuật quốc tế Gettext (.po) & XLIFF
├── UrlHtmlCrawler.php              # Thu thập và bóc tách văn bản từ đường dẫn URL trang web
├── TranslationRepository.php       # Thao tác CRUD trên bảng strings và translations
│
├── [Nhóm 4: Hỗ trợ Dịch thuật Thông minh & Kiểm soát Chất lượng]
├── SuggestionEngine.php            # Động cơ tổng hợp gợi ý dịch từ TM, Glossary và AI
├── TranslationMemoryManager.php    # Quản lý kho Bộ nhớ dịch (TM) & tìm kiếm mờ pg_trgm
├── GlossaryManager.php             # Quản lý từ điển thuật ngữ chuyên ngành bắt buộc
├── AiTranslator.php                # Kết nối API dịch máy tự động (MyMemory / AI)
├── VariableValidator.php           # Kiểm tra bảo toàn biến giữ chỗ ({var}, %s, {{count}})
├── LockManager.php                 # Cấp và giải phóng khóa chống sửa trùng (translation_locks)
│
├── [Nhóm 5: Kiểm duyệt, Cộng tác & Phát hành]
├── ReviewManager.php               # Quy trình duyệt/từ chối bản dịch & lưu lịch sử phiên bản
├── CommentManager.php              # Quản lý luồng thảo luận, bình luận theo từng chuỗi
├── NotificationManager.php         # Gửi và quản lý thông báo sự kiện theo thời gian thực
├── Mailer.php                      # Dịch vụ gửi email thông báo và mã OTP qua SMTP
├── ReleaseExporter.php             # Đóng băng phiên bản, tạo Snapshot & đóng gói ZIP/JSON
├── WebhookSyncService.php          # Đẩy đồng bộ gói ngôn ngữ qua API Endpoint & hỗ trợ Rollback
└── AuditLogger.php                 # Ghi nhật ký kiểm toán mọi thao tác dữ liệu kèm IP
```

---

## 2. Chi tiết Tầng Cơ sở dữ liệu (`Ma_nguon/database/` - 21 Migrations)

Hệ thống quản lý lịch sử thay đổi cấu trúc bảng thông qua chuỗi 21 tệp SQL nâng cấp tuần tự:

```text
Ma_nguon/database/
├── schema.sql                      # Lược đồ gốc khởi tạo 17 bảng, chỉ mục GIN/pg_trgm & Trigger
├── project_locales.sql             # Cấu hình ánh xạ ngôn ngữ đích theo dự án
├── db.php                          # Nạp dữ liệu khởi tạo mẫu (Tài khoản Admin, ngôn ngữ chuẩn)
└── migrations/
    ├── 001_init_users_and_roles.sql            # Khởi tạo bảng users và phân quyền hệ thống
    ├── 002_create_projects_and_members.sql     # Tạo bảng projects và project_members
    ├── 003_create_locales_tables.sql           # Tạo danh mục ngôn ngữ locales & project_locales
    ├── 004_create_source_versions.sql          # Tạo bảng quản lý phiên bản nguồn
    ├── 005_create_strings_table.sql            # Tạo bảng lưu trữ khóa văn bản gốc đã làm phẳng
    ├── 006_create_translations_table.sql       # Tạo bảng lưu trữ bản dịch theo từng ngôn ngữ
    ├── 007_add_optimistic_locking.sql          # Bổ sung cột updated_at chống ghi đè dữ liệu
    ├── 008_create_translation_locks.sql        # Tạo bảng khóa bản ghi tạm thời khi đang dịch
    ├── 009_create_reviews_table.sql            # Tạo bảng lưu kết quả kiểm duyệt bản dịch
    ├── 010_create_translation_history.sql      # Tạo bảng lưu vết lịch sử chỉnh sửa từng câu
    ├── 011_create_translation_comments.sql     # Tạo bảng thảo luận/bình luận trên chuỗi
    ├── 012_enable_pg_trgm_extension.sql        # Kích hoạt extension pg_trgm tìm kiếm tương đồng
    ├── 013_create_translation_memory.sql       # Tạo kho Bộ nhớ dịch (TM) & đánh chỉ mục GIN
    ├── 014_create_glossary_table.sql           # Tạo bảng Từ điển thuật ngữ chuyên ngành
    ├── 015_create_release_snapshots.sql        # Tạo bảng lưu bản chụp phát hành bất biến
    ├── 016_create_release_sync_history.sql     # Tạo bảng lịch sử đồng bộ API và Rollback
    ├── 017_add_freeze_triggers.sql             # Tạo Trigger khóa cứng dữ liệu khi đóng băng bản
    ├── 018_create_audit_logs.sql               # Tạo bảng nhật ký kiểm toán toàn hệ thống
    ├── 019_create_notifications_table.sql      # Tạo bảng thông báo cho thành viên dự án
    ├── 020_create_support_tickets.sql          # Tạo bảng tiếp nhận yêu cầu hỗ trợ người dùng
    └── 021_add_email_verification_otp.sql      # Bổ sung cột mã OTP xác thực email
```

---

## 3. Chi tiết Mở rộng Giao diện React SPA (`dashboard-ui/src/`)

Bên cạnh các component dùng chung đã nêu, ứng dụng React SPA được tổ chức thêm các thư mục nghiệp vụ chuyên sâu:

```text
Ma_nguon/dashboard-ui/src/
├── pages/                          # Các màn hình chức năng chính của Dashboard SPA
│   ├── DashboardOverview.tsx       # Trang tổng quan tiến độ các dự án & biểu đồ thống kê
│   ├── ProjectList.tsx             # Danh sách dự án, tìm kiếm và bộ lọc trạng thái
│   ├── ProjectSettings.tsx         # Cấu hình dự án, phân quyền thành viên & API Keys
│   ├── TranslationMemoryPage.tsx   # Giao diện tra cứu, thêm/sửa/nhập kho Bộ nhớ dịch (TM)
│   ├── ReleaseHistoryPage.tsx      # Quản lý các bản phát hành, tải gói ZIP/JSON & Rollback
│   └── AuditLogsPage.tsx           # Giao diện tra cứu nhật ký kiểm toán (dành cho Admin)
│
├── services/                       # Tầng giao tiếp API với Backend PHP
│   ├── apiClient.ts                # Cấu hình Fetch/Axios đính kèm CSRF Token & xử lý lỗi
│   ├── projectService.ts           # Các hàm gọi API quản lý dự án và phiên bản
│   └── tmService.ts                # Các hàm gọi API truy vấn và cập nhật Bộ nhớ dịch
│
├── hooks/                          # Các Custom React Hooks tái sử dụng
│   ├── usePwaInstall.ts            # Hook quản lý trạng thái cài đặt PWA & sự kiện trình duyệt
│   └── useAuth.ts                  # Hook kiểm tra phiên đăng nhập và quyền hạn người dùng
│
└── types/                          # Định nghĩa kiểu dữ liệu TypeScript (Interfaces/Types)
    ├── project.ts                  # Kiểu dữ liệu cho Project, Version, Locale
    └── translation.ts              # Kiểu dữ liệu cho StringItem, Translation, TMRecord
```

---

## 4. Chi tiết Bộ Kiểm thử Tự động (`Ma_nguon/tests/` - 14 Kịch bản)

```text
Ma_nguon/tests/
├── run.php                         # Trình điều phối chạy toàn bộ 14 bộ kiểm thử tự động
├── helper.php                      # Thư viện hàm khẳng định (same, ok, throws) và tạo dữ liệu test
├── auth_test.php                   # Kiểm thử đăng ký, xác thực OTP, đăng nhập và phân quyền RBAC
├── csrf_security_test.php          # Kiểm thử cơ chế chặn request thiếu hoặc sai mã CSRF
├── json_flatten_test.php           # Kiểm thử thuật toán làm phẳng & tái tạo cây JSON đa cấp
├── import_parser_test.php          # Kiểm thử đọc dữ liệu từ JSON, CSV, PO và XML
├── version_diff_test.php           # Kiểm thử phát hiện chuỗi thêm mới, sửa đổi và xóa bỏ
├── suggestion_tm_test.php          # Kiểm thử gợi ý khớp chính xác & tìm kiếm mờ pg_trgm
├── variable_validator_test.php     # Kiểm thử phát hiện thiếu/sai biến giữ chỗ ({var}, %s)
├── concurrency_lock_test.php       # Kiểm thử khóa tạm thời & Optimistic Locking chống ghi đè
├── review_workflow_test.php        # Kiểm thử luồng duyệt/từ chối và lưu lịch sử bản dịch
├── freeze_trigger_test.php         # Kiểm thử Trigger PostgreSQL chặn sửa/xóa bản đã đóng băng
├── release_export_test.php         # Kiểm thử tạo Release Snapshot và xuất tệp gói ngôn ngữ
├── audit_log_test.php              # Kiểm thử ghi nhận đầy đủ nhật ký thao tác hệ thống
└── pwa.php                         # Bộ kiểm thử 20 tiêu chí Manifest, Service Worker và Router PWA
```
## 🚀 4. Hướng dẫn cài đặt và chạy dự án cục bộ (Local)

### Cách 1: Chạy nhanh bằng Docker (Khuyên dùng)

1. **Clone kho lưu trữ về máy:**
   ```bash
   git clone https://github.com/IAMIOT1/PTPMMNM-CNTT-1812-NHOM-12.git
   cd PTPMMNM-CNTT-1812-NHOM-12/Ma_nguon
   ```

2. **Build giao diện Frontend SPA (`dashboard-ui`):**
   ```bash
   cd dashboard-ui
   npm install
   npm run build
   cd ..
   ```

3. **Khởi chạy container với Docker:**
   ```bash
   docker build -t enterprise-tms .
   docker run -d -p 8080:80 \
     -e PGHOST=your_pg_host \
     -e PGPORT=5432 \
     -e PGDATABASE=tms_db \
     -e PGUSER=postgres \
     -e PGPASSWORD=your_password \
     enterprise-tms
   ```
   Truy cập ứng dụng tại: `http://localhost:8080`

### Cách 2: Cài đặt thủ công trên môi trường Local (PHP + PostgreSQL)

1. **Yêu cầu hệ thống:**
   * PHP >= 8.1 (bật các extension: `pgsql`, `pdo_pgsql`, `mbstring`, `json`, `zip`, `curl`)
   * PostgreSQL >= 15 (có hỗ trợ extension `pg_trgm`)
   * Node.js >= 18 & npm

2. **Khởi tạo Cơ sở dữ liệu:**
   ```bash
   psql -U postgres -c "CREATE DATABASE tms_db;"
   psql -U postgres -d tms_db -f database/schema.sql
   ```

3. **Cấu hình biến môi trường & Build UI:**
   * Thiết lập các biến môi trường kết nối CSDL (`PGHOST`, `PGPORT`, `PGDATABASE`, `PGUSER`, `PGPASSWORD`).
   * Build thư mục giao diện React:
     ```bash
     cd dashboard-ui && npm install && npm run build && cd ..
     ```

4. **Khởi động máy chủ phát triển PHP:**
   ```bash
   php -S localhost:8000
   ```

---

## 🧪 5. Kiểm thử tự động (Automated Testing)

Dự án đi kèm bộ 14 kịch bản kiểm thử tích hợp và kiểm thử đơn vị (xác thực bảo mật CSRF, phân quyền RBAC, làm phẳng JSON, khóa đồng thời, Trigger đóng băng dữ liệu và cấu hình PWA):

```bash
cd Ma_nguon
php tests/run.php
php tests/pwa.php
```

---

## 👥 6. Phân quyền người dùng (RBAC)

| Vai trò | Phạm vi | Quyền hạn chính |
| :--- | :--- | :--- |
| **Admin** | Toàn hệ thống | Quản lý người dùng, phân quyền toàn cục, giám sát dự án, tra cứu nhật ký kiểm toán (`Audit Logs`). |
| **Team Lead** | Dự án | Tạo dự án, mời thành viên, kích hoạt ngôn ngữ đích, import nguồn, đóng băng phiên bản và xuất bản gói ngôn ngữ. |
| **Translator** | Dự án | Biên dịch chuỗi nguồn, sử dụng gợi ý TM/AI/Glossary, xem Live Preview và trao đổi bình luận. |
| **Reviewer** | Dự án | Kiểm duyệt chất lượng bản dịch, phê duyệt (`Approved`) hoặc từ chối (`Rejected`) kèm phản hồi. |

---

## 📄 7. Giấy phép

Dự án được phát hành phục vụ mục đích học tập và nghiên cứu theo giấy phép mã nguồn mở đính kèm trong tệp `LICENSE`.
