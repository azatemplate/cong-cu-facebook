# Thông tin Tổng quan Dự án (Dành cho AI & Người dùng)

File này lưu trữ toàn bộ bức tranh kiến trúc kỹ thuật của hệ thống để đảm bảo tính liên tục cho các bản cập nhật lần sau. Khi có phiên trò chuyện mới, AI hãy ưu tiên đọc file này để nắm bắt bối cảnh.

## 1. Thông tin Chung
- **Tên Dự án**: Facebook & YouTube AI Automation Tool
- **Thư mục làm việc Local**: `d:\pagespeed\hi\facebook\`
- **Tính năng cốt lõi**: Tự động đăng tải nội dung (bài viết, Video Tiktok, Drive, Youtube), tự động bình luận, phân quyền nhiều Fanpage bằng hệ thống Multi-process & Cấu trúc Cron.
- **Kho lưu trữ Git**: `https://github.com/azatemplate/cong-cu-facebook.git`
- **Tình trạng nhánh**: Push code trực tiếp thẳng lên nhánh `main`.

## 2. Kiến trúc Máy chủ (Production / VPS)
- Dự án được đóng gói bằng **Docker** + **Docker Compose** để chạy Production trên VPS Ubuntu.
- Triển khai **1-Click**: Mọi cài đặt được kích hoạt thông qua file `/install.sh` kéo từ Github. Kịch bản này tự build Docker, tự tải Caddy Router để thiết lập SSL cho Domain, tự chạy ngầm MySQL & cron.
- **Thư mục trên VPS**: `/opt/facebook-automation/`
- Cơ chế Update Cập Nhật trên máy chủ thực tế: SSH vào VPS, tìm đến thư mục cài đặt và chạy file bash: `bash update.sh`

## 3. Worker Cơ chế Vòng Lặp & Khắc phục Lỗi Lịch sử
- File `cron/start_publish.php` và `cron/start_comment.php` đóng vai trò là Dispatcher. Chúng quét CSDL mỗi 1 phút thông qua Cron của Ubuntu (được định nghĩa trong `docker/crontab`).
- Tiến trình con độc lập chạy `publish_worker.php`. Nếu khởi chạy ở dạng **Web-AJAX**, CURL timeout là `1500ms`. 
- **⚠ ĐIỀU LƯU Ý BẮT BUỘC:** Vì gọi bằng CURL thời gian cực ngắn (bỏ kết nối ngay), các file Worker (`publish_worker.php`, `comment_worker.php`) luôn phải có `ignore_user_abort(true);` và `set_time_limit(0);` ở dòng đầu tiên để script không bị chết/treo giữa chừng khi server web cắt kết nối mạng.
- **Xử lý Thundering Herd (Chống sập Database):** Dispatcher `start_publish.php` không dùng `LIMIT` để bảo vệ hiệu năng chạy Real-time cho mọi user. Thay vào đó, trong các Worker (`publish_worker.php`, `comment_worker.php`) được chèn một đoạn `usleep(rand(50000, 800000));` ngay trước khi kết nối DB. Việc giãn cách vài mili-giây siêu nhỏ này giúp MySQL không bị sập (`Too many connections`) khi nhận hàng trăm luồng kết nối tạo ra cùng 1 giây.
- **Lỗi vòng lặp vô tận do Retry Count:** Nếu truy vấn SQL trong Worker check thiếu `(retry_count IS NULL OR retry_count < 3)`, Worker sẽ từ chối nhận bài failed cũ mặc dù Dispatcher vẫn tiếp tục bốc bài đó đưa lên mỗi phút. Đã Fix logic để bao phủ cả trường hợp NULL.

## 4. Kiến trúc Bảo mật (Security)
- **Quản lý Credentials**: Mật khẩu Database và Encryption Key được lưu trữ hoàn toàn trong file `.env` (không bao giờ commit file này lên Git). Mẫu tham khảo ở `.env.example`.
- **Module Bảo mật Centralized**: File `includes/security.php` cung cấp các hàm toàn cục: `csrf_token()`, `verify_csrf()`, `require_auth()`, và rate limiting.
- **Bảo vệ API & Endpoint**: Mọi action endpoints (`actions/`) đều bắt buộc kiểm tra session_start và `$_SESSION['account_id']` trước khi xử lý, ngăn chặn truy cập ẩn danh. Tất cả form POST đều yêu cầu kèm mã CSRF.
- **Server Guard**: `.htaccess` chặn truy cập trực tiếp vào các file cấu hình, `.env`, `.sql`, `.log` và thư viện nhạy cảm.

## 5. Workflows Tự Động (AI Skills)
- Hệ thống có nạp sẵn một phím tắt Workflow ở `.agents/workflows/update_github.md`. 
- Nếu người dùng cần commit code lên Github, AI chỉ cần nhận lệnh `/update_github`, đọc file thay đổi và gọi chuỗi lệnh git (Thêm, Commit Message Tiếng Anh ngắn gọn, và Push).

## 6. Lịch sử Cập nhật Gần đây
- Bổ sung hiển thị số lượng kênh YouTube đã kết nối trực tiếp trong bảng hiển thị dữ liệu của màn hình Quản lý Tài khoản (Accounts Admin).
- Nâng cấp AI Rewriter: Hỗ trợ tự động nội suy biến `{fanpage_name}` vào các Prompt hệ thống của Gemini/OpenAI khi thực thi tự động Auto-Publish cho các Page Facebook.
- Đã bổ sung tính năng tự động so sánh số liệu (Reach, Views, Followers) của ngày hôm nay so với hôm qua bằng Snapshot (hiển thị phần trăm tăng/giảm trên `index.php`).
- Cải tổ lại hoàn toàn diện mạo và văn bản hiển thị trên trang đăng nhập `login.php` bằng giao diện Split-Screen hiện đại.
- **[MỚI] Tích hợp trang `tiktok_search.php`**: Công cụ tìm kiếm video TikTok tích hợp sẵn vào sidebar (menu trước Insights). Gồm 2 tab chính:
  - **Tab Từ khóa**: Tìm video theo keyword qua API `tikwm.com/api/feed/search`.
  - **Tab Hashtag**: Tìm hashtag gợi ý theo keyword (`api/challenge/search`) → hiển thị dạng card clickable → click chọn hashtag sẽ tự động tải video (`api/challenge/posts`). Hỗ trợ thêm `api/challenge/info` để lấy thông tin hashtag chi tiết.
  - Bảng kết quả hỗ trợ: sort theo cột, toggle hiển thị/ẩn cột (lọc cột), chọn checkbox hàng loạt, copy URL TikTok hàng loạt vào clipboard.
  - **Lưu ý kiến trúc AJAX**: Toàn bộ handler AJAX (`?ajax=keyword|hashtag_search|hashtag_info|hashtag`) phải nằm **trước** `require_once 'includes/header.php'` — header.php xuất HTML, nếu AJAX handler nằm sau sẽ trả về HTML thay vì JSON gây lỗi `Unexpected token '<'`.
  - **Lưu ý challenge_id**: TikTok dùng Snowflake ID 64-bit, không dùng `intval()` để tránh overflow trên server 32-bit. Lưu và truyền dưới dạng string, validate bằng `is_numeric()`.

## 7. Kiến trúc TikTok API (tikwm.com)
- **Host**: `https://www.tikwm.com`
- **Tìm video theo keyword**: `api/feed/search` — params: `keywords`, `count`, `cursor`
- **Tìm hashtag theo keyword**: `api/challenge/search` — params: `keywords`, `count`, `cursor` — trả về `challenge_list[{id, cha_name, user_count, view_count}]`
- **Chi tiết hashtag**: `api/challenge/info` — param: `challenge_name` — trả về `{id, cha_name, user_count, view_count}`
- **Video theo hashtag**: `api/challenge/posts` — params: `challenge_id` (string), `count`, `cursor` — trả về `videos[]`
- **Fields chuẩn của video**: `['video_id', 'region', 'duration', 'title', 'play_count', 'digg_count', 'comment_count', 'share_count', 'download_count', 'create_time', 'music_info', 'author']`

## 8. Cấu hình Cron Dọn Dẹp Server (Server Disk Cleanup Script - 5 Phút/Lần)
Khi người dùng hoặc hệ thống chạy tự động liên tục, ổ đĩa server có thể bị phình to bởi log, thùng rác aaPanel, file tạm cURL/TikTok/Drive/Chunks trong `/tmp`, `/temp` và `uploads/tmp`.
Kịch bản Bash tối ưu dọn dẹp tổng thể (Cron: 5 phút / 1 lần, dạng Shell Script trên aaPanel):

```bash
#!/bin/bash
# =========================================================
# Script Dọn Dẹp Ổ Đĩa Siêu Tốc (Chạy 5 Phút / 1 Lần)
# =========================================================

# 1. Dọn dẹp Thùng rác aaPanel
rm -rf /.Recycle_bin/* 2>/dev/null
rm -rf /www/trash/* 2>/dev/null

# 2. Xóa file rác trong /tmp của Linux (Cũ hơn 5 PHÚT)
find /tmp -type f \( -name "*.mp4" -o -name "*.mov" -o -name "*.avi" -o -name "*.tmp" -o -name "*.png" -o -name "*.jpg" -o -name "gdrive_*" -o -name "curl_*" -o -name "yt_tik_*" -o -name "buf_chk_*" \) -mmin +5 -delete 2>/dev/null

# 3. Xóa file tạm trong thư mục temp & uploads/tmp của website (Cũ hơn 5 PHÚT)
find /www/wwwroot/fbweb.hongdolab.com/temp /www/wwwroot/fbweb.hongdolab.com/uploads/tmp -type f -mmin +5 -delete 2>/dev/null

# 4. Dọn dẹp website data.hongdolab.com (Cũ hơn 5 PHÚT)
find /www/wwwroot/data.hongdolab.com/uploads /www/wwwroot/data.hongdolab.com/uploads_tmp -type f -mmin +5 -delete 2>/dev/null

# 5. Gọi php cleanup.php dọn dẹp ngầm
php /www/wwwroot/fbweb.hongdolab.com/cron/cleanup.php >/dev/null 2>&1

# 6. Làm rỗng File Log phình to (> 10MB)
find /www/server/data/ -name "*.err" -size +10M -exec truncate -s 0 {} \; 2>/dev/null
find /www/wwwlogs/ -name "*.log" -size +10M -exec truncate -s 0 {} \; 2>/dev/null
find /www/wwwroot/ -name "*.log" -size +10M -exec truncate -s 0 {} \; 2>/dev/null
find /tmp/ -name "fb_*.log" -size +10M -exec truncate -s 0 {} \; 2>/dev/null

# 7. Thu hẹp nhật ký hệ thống Linux về 50MB
journalctl --vacuum-size=50M 2>/dev/null
```



