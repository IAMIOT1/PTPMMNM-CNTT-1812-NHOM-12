# Lab 03 — Đề tài 3: Hệ thống quản lý bản dịch và phát hành gói ngôn ngữ

Phiên bản Lab 03 (hiển thị danh sách, tìm kiếm, lọc và xử lý form) viết lại theo đề tài
**Xây dựng hệ thống quản lý bản dịch và phát hành gói ngôn ngữ cho phần mềm**.

| Lab 03 gốc (sự kiện) | Đề tài 3 (gói ngôn ngữ) |
| --- | --- |
| Sự kiện (`events`) | Gói ngôn ngữ (`packs`) |
| Loại sự kiện `category` | Nền tảng `platform` (web/mobile) |
| Còn chỗ (`capacity - registered`) | Còn chuỗi chưa dịch (`total - translated`) |
| Form đăng ký tham dự | Form gửi đề xuất bản dịch |

```bash
bash lab.sh check
bash lab.sh run      # http://127.0.0.1:8103 và /contribute.php
bash lab.sh test     # TESTS=7 FAILED=0
bash lab.sh extend   # TESTS=8 FAILED=0
```

- Trang chủ: tìm theo tên gói, lọc theo nền tảng, chỉ gói chưa dịch xong (AND); giữ lại lựa chọn sau khi gửi.
- `/contribute.php`: kiểm tra phía máy chủ, hiển thị `PREVIEW_OK` hoặc mã lỗi; chưa lưu CSDL.
- Bài tập mở rộng nằm ở `src/PackFilter.php` và `public/index.php`.

Dữ liệu giả lập, không dùng thông tin cá nhân thật. Không đưa `.local` vào bài nộp.
