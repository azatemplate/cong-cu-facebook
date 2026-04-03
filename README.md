# Facebook & YouTube AI Automation Tool

Công cụ tự động hóa toàn diện giúp quản lý và tối ưu hóa quy trình đăng bài, tự động bình luận, và theo dõi số liệu cho hệ thống Fanpage trực thuộc Facebook và Kênh YouTube. Hệ thống sử dụng công nghệ tự động hóa với Docker đi kèm kịch bản "1-Click Deploy", chuyên biệt dành cho cài đặt trên VPS (Ubuntu/Debian).

## Tính năng nổi bật
* Tự động đăng tải nội dung (Bài viết, Reel, Video, Chia sẻ ảnh) từ Local, Link TikTok, và Google Drive.
* Tích hợp AI Rewriter (Gemini/ChatGPT) tự động xào trộn và thay đổi tiêu đề chống SPAM.
* Quản lý tập trung không giới hạn số lượng Fanpage thông qua Token ảo hóa.
* Hỗ trợ lên lịch đăng nội dung số lượng lớn.
* Hệ thống Auto-Comment tự động thả bình luận "mồi" để tăng tương tác sau khi bài được đăng.
* Thống kê Dashboard tập trung các lượt tương tác (views, followers...).

---

## Yêu cầu Hệ thống cài đặt
- 1 Server / VPS chạy **Ubuntu 20.04/22.04** hoặc **Debian 11/12** (Khuyến khích VPS trống mới mua để tránh xung đột mạng/ cổng 80).
- Trỏ sẵn Tên miền (Domain) của bạn về IP của VPS nếu bạn muốn chạy qua tên miền (sẽ được tự động cài bảo mật SSL/HTTPS).
- Kho GitHub của bạn phải đang ở chế độ Public để tải về trong bước cài đặt.

---

## 🚀 Hướng Dẫn Cài Đặt (1 Lệnh Duy Nhất)

Vào máy chủ (VPS) của bạn bằng tài khoản `root` (hoặc có sudo), copy và dán nguyên xi dòng lệnh sau:

```bash
wget -O install.sh https://raw.githubusercontent.com/azatemplate/cong-cu-facebook/main/install.sh && bash install.sh
```

**Quá trình tương tác khi cấu hình (Interactive Console):**
1. Màn hình sẽ nhắc bạn nhập **Tên miền (Domain)**: Bạn có thể điền (Ví dụ: tool.domain.com). Nếu có điền, tool sẽ tải Caddy Reverse Proxy & thiết lập tự động chững chỉ xanh SSL. Nếu không muốn xài tên miền, nhấn Enter bỏ trống để chạy trực tiếp IP qua cổng 80.
2. Chờ 3-4 phút cho tới khi màn hình hiển thị lời chào **CÀI ĐẶT HOÀN TẤT & HỆ THỐNG ĐÃ SẴN SÀNG CHẠY!**.

> **Tài khoản Đăng nhập Hệ Thống mặc định ban đầu:**
> Username: `admin` \
> Password: `admin123`

---

## 🔄 Cập Nhật Hệ Thống (Auto-Update)

Bất cứ lúc nào hệ thống có bản vá mới trên nhánh Main, bạn chỉ việc dùng 1 trong 2 lệnh sau trên giao diện Console/Terminal của VPS (chọn 1 lệnh tùy theo cách bạn cài đặt):

**👉 Cách 1: NẾU BẠN CÀI LÊN AAPANEL HOẶC VPS TRỰC TIẾP (Native / cPanel / Không dùng Docker)**
```bash
curl -sL https://raw.githubusercontent.com/azatemplate/cong-cu-facebook/main/update_server.sh | bash
```

**👉 Cách 2: NẾU BẠN ĐANG DÙNG CÀI ĐẶT DOCKER (`install.sh`)**
```bash
bash /opt/facebook-automation/update.sh
```

Hệ thống sẽ kéo Git tự động, cấu hình phân quyền và Restart trơn tru cực nhanh mà không làm hỏng Database đang có.

---

## ⚙️ Cấu Hình (Setup Tool) Cơ Bản Bên Trong

Sau khi vào được Tool ở đường dẫn Web, để hệ thống có thể liên kết mượt mà với các mạng xã hội, bạn phải tinh chỉnh:
1. **Thiết lập Nhóm quyền (App ID Facebook):** Tại trang cài đặt (Settings), hãy nhập `FB App ID` và `App Secret`.
2. **Khai báo Callback URL (OAuth):** Nếu bạn đăng ký App qua Facebook Developers hoặc Google Cloud Console, bắt buộc phải sao chép chính xác 3 đường dẫn này điền vào ô "Valid OAuth Redirect URIs" tương ứng trên cổng Dev:
   - Facebook Login Callback: `https://domain_cua_ban/redirect_callback.php`
   - Google Drive Callback: `https://domain_cua_ban/google_callback.php`
   - YouTube Chage Callback: `https://domain_cua_ban/youtube_callback.php`
   > *(Nếu chạy IP localhost, thay `https://domain_cua_ban` bằng IP)*
3. **Cấu hình AI OpenAI / Gemini:** Nếu cần dùng tính năng tạo Caption (Spinner) ngẫu nhiên, hãy nhập API Key vào Cấu Hình AI trên Admin Dashboard.
4. Các tiến trình Background Worker (CronJob lặp mỗi 1 phút để test bài mới) chạy ngầm hoàn toàn bên trong Docker. Bạn không cần làm gì thêm ở ngoài Server Ubuntu!

---

## 📝 Quản Lý Docker Root
* Dữ liệu MySQL Database: Mapping tại `/opt/facebook-automation/db_data/`. Backup thư mục này là đảm bảo an toàn tuyệt đối.
* Media (Ảnh/Video gốc tải lên máy hoặc TikTok lưu trữ): Mapping tại `/opt/facebook-automation/uploads/`.
* Xem màn hình theo dõi Log để Debug: Gõ `cd /opt/facebook-automation && docker-compose logs -f`

---

## 🛡️ Bản cập nhật Bảo mật (Security Update) - 2026
Hệ thống vừa được nâng cấp toàn diện về mặt bảo mật để ngăn chặn xâm nhập và rủi ro rò rỉ dữ liệu:
- **Tách riêng Credentials**: Chuyển mật khẩu Database và Encryption Key khỏi `config.php` sang file `.env` ẩn, ngăn không cho commit lên Github.
- **Bảo vệ CSRF toàn cục**: Tích hợp module `security.php`, bắt buộc xác thực CSRF Token trên mọi form POST và chức năng thay đổi dữ liệu.
- **Xác thực tự động (Auth Guards)**: Mọi API Endpoint gọi ngầm (`send_message`, `mark_read`...) đều được bọc lớp kiểm tra `session_start` để chặn người vô danh gọi API.
- **Ngừa SQL Injection**: Chuyển các truy vấn nối chuỗi cũ bằng cơ chế **Prepared Statements** an toàn tuyệt đối.
- **Tự động vá Header & Rate Limit**: Rate Limiting đăng nhập bằng IP (Khóa 15 phút nếu sai 5 lần), kèm các HTTP Security Headers (`nosniff`, `SAMEORIGIN`, `XSS-Protection`).
- **Khóa `.htaccess` đa tầng**: Ngăn truy cập vào các file cấu hình `.env`, thư mục `.git`, các tệp tin `.sql` kết xuất và file `error_log` nhạy cảm trên máy chủ. Mặc định cấp quyền upload file 0755 thay vì 0777.
