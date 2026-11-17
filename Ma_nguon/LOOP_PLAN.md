# LOOP_PLAN.md

## 1. Mục tiêu
- Chuẩn hóa và xử lý triệt để lỗi mã ngôn ngữ (như lỗi 'AB', 'AA'...) khi gọi MyMemory API, đảm bảo **tất cả các ngôn ngữ hiển thị trong danh sách lựa chọn trên giao diện (UI dropdown)** đều có thể dịch được hoặc có cơ chế fallback an toàn.
- Đảm bảo **tính đồng bộ dữ liệu giữa 3 trang (Trưởng nhóm, Kiểm duyệt, Biên dịch)**: khi có thay đổi/thêm mới dự án hoặc phân công ở bất kỳ trang nào, các trang còn lại phải được đồng bộ chính xác, không bị lệch trạng thái.
- Đảm bảo **lịch sử thao tác (Action History/Logs) được lưu trữ bền vững** vào cơ sở dữ liệu, không bị mất khi thoát ra và đăng nhập/quay lại hệ thống.

## 2. Danh sách công việc

| Mã | Công việc | Tiêu chí đạt | Trạng thái |
|---|---|---|---|
| M01 | Khảo sát service gọi MyMemory API | Xác định chính xác vị trí mã nguồn PHP nhận tham số ngôn ngữ và thực hiện gọi API MyMemory | Đã xong |
| M02 | Xây dựng hàm chuẩn hóa mã ngôn ngữ | Viết hàm `normalizeLanguageCode` để làm sạch, chuyển chữ thường và map chuẩn mã ISO 2 chữ cái cho mọi ngôn ngữ | Đã xong |
| M03 | Đồng bộ ngôn ngữ UI với API dịch thuật | Đảm bảo danh sách ngôn ngữ hiển thị trong dropdown trên giao diện (ví dụ AB, AF, SQ, AM...) được ánh xạ chuẩn xác và API phải xử lý được tất cả các ngôn ngữ này | Đã xong |
| M04 | Cập nhật logic API và xử lý ngoại lệ / Fallback | Đảm bảo truyền `langpair` chính xác; bọc try-catch để nếu ngôn ngữ nào API không hỗ trợ thì tự động fallback an toàn (vd: về `en`) hoặc báo cáo chi tiết mà không làm sập tiến trình dịch | Đã xong |
| M05 | Kiểm thử đa ngôn ngữ trên hệ thống | Thử nghiệm dịch thực tế với các ngôn ngữ trong danh sách UI, xác nhận không còn lỗi `INVALID TARGET LANGUAGE` | Đã xong |
| M06 | Chuyển hàng đợi sang dịch tuần tự | Gửi từng câu riêng lẻ, chờ giữa các request, cập nhật dòng và tiến độ ngay sau từng kết quả | Đã xong |
| M07 | Kiểm tra & đồng bộ dữ liệu 3 trang (Trưởng nhóm, Kiểm duyệt, Biên dịch) | Khi thêm hoặc thay đổi dự án ở 1 trong 3 trang, dữ liệu phải tự động đồng bộ sang 2 trang còn lại; rà soát, sửa lỗi nếu có độ trễ hoặc mất kết nối dữ liệu giữa các role. | Đã xong |
| M08 | Thiết lập cơ chế lưu trữ lịch sử thao tác vĩnh viễn | Xây dựng bảng/model lưu log lịch sử thao tác, đảm bảo ghi nhận đầy đủ hành động (ai, làm gì, lúc nào) và dữ liệu không bị mất khi thoát trang/đăng xuất. | Đã xong |

## 3. Quy trình thực hiện
1. Đọc mục tiêu, danh sách công việc và nhật ký.
2. Chọn một việc đang Chờ làm hoặc Đang làm. Nếu phát hiện việc còn thiếu trong phạm vi mục tiêu, thêm vào danh sách kèm tiêu chí đạt.
3. Đổi trạng thái thành Đang làm. Giao việc cho agent phụ nếu công cụ hỗ trợ. Nếu chỉ có một agent thì tự thực hiện.
4. Chạy test hoặc thao tác trên ứng dụng để kiểm tra từng tiêu chí.
5. Nếu chưa đạt, tìm nguyên nhân, sửa và kiểm tra lại.
6. Nếu mọi tiêu chí đều đạt, đổi trạng thái thành Đã xong, ghi nhật ký và quay lại bước 2 để chọn việc tiếp theo.

## 4. Điều kiện dừng
- Hết việc có thể thực hiện thì tổng kết.
- Nếu một việc vẫn lỗi sau 3 lần sửa và kiểm tra lại, đánh dấu Bị kẹt, ghi lý do rồi chuyển sang việc khác nếu có thể làm độc lập.
- Nếu thiếu môi trường hoặc dữ liệu để kiểm tra, ghi rõ thứ còn thiếu.

## 5. Nhật ký
| Mã việc | Đã làm gì | Cách kiểm tra và kết quả | Việc tiếp theo |
|---|---|---|---|
| M01 | Khảo sát service và bổ sung yêu cầu tương thích toàn bộ ngôn ngữ trên UI dropdown vào kế hoạch | Đã cập nhật mục tiêu bám sát danh sách ngôn ngữ giao diện | Thực hiện M02 |
| M02 | Thêm `normalizeLanguageCode` với chuẩn hóa chữ thường, vùng miền, alias cũ và kiểm tra đủ 183 mã UI | `php -l` đạt; 2 kiểm thử chuẩn hóa đạt. Bộ i18n có 1 lỗi độc lập về bulk status | Thực hiện M03 |
| M03 | Ánh xạ toàn bộ mã ISO-639-1 của `LanguageCatalog` qua chuẩn hóa trước khi tạo `langpair` | Kiểm thử đủ 183 mã UI và alias vùng miền đạt | Thực hiện M04 |
| M04 | Thêm dịch dự phòng sang `en` khi MyMemory báo mã đích không hợp lệ hoặc không có kết quả; trả metadata cảnh báo và tiếp tục xử lý từng câu | PHP lint đạt; live probe `vi→af`, `vi→ab`, `vi→aa` cho thấy mã hợp lệ chạy, hai mã lỗi được fallback sang `en` | Thực hiện M05 |
| M05 | Kiểm thử trực tiếp MyMemory cho `af`, `ab`, `aa`, `sq`, `am`, `zh`, `zu` | Các mã hợp lệ dịch được; `ab`/`aa` được fallback sang `en`; không ngắt luồng | Thực hiện M06 |
| M06 | Thay nhánh batch/concurrency bằng vòng lặp mỗi câu một request; chờ 1,2 giây, render input và cập nhật tiến độ sau từng response | PHP lint và các contract tuần tự/live-progress đạt; i18n còn 1 lỗi độc lập về bulk status. | Thực hiện M07 |
| M07 | Thêm fingerprint trạng thái dự án/phân công dùng chung và endpoint polling; tự tải lại khi thay đổi, giữ nguyên trang đang nhập để tránh mất dữ liệu | `php -l`, `git diff --check` và `tests/project_sync.php` (6/6) đạt; xác nhận cùng trạng thái được chia sẻ cho Trưởng nhóm/Kiểm duyệt/Biên dịch | Thực hiện M08 |
| M08 | Bổ sung lưu vết tạo/xóa dự án, thêm/đổi/xóa phân công; lưu actor, target, hành động, thời gian; giữ audit khi project bị xóa và trình bày chi tiết trong giao diện | Migration `migrate_audit_retention.php` chạy thành công 2 lần; `tests/audit_retention.php` 9/9 và `tests/system_admin.php` 81/81 đạt | Hoàn tất |

## 6. Việc tiếp theo
Toàn bộ công việc trong kế hoạch đã hoàn tất.