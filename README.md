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
- 1 Server / VPS chạy **Ubuntu 20.04/22.04** hoặc **Debian 11/12** (Khuyến khích VPS trống mới mua để tránh xung đột).
- Ram tối thiểu 2GB.
- Đã cài đặt Git (Hệ thống sẽ hỗ trợ cài đặt các nền tảng khác nếu thiếu).

---

## 🚀 Hướng Dẫn Cài Đặt Lần Đầu Mới (1-Click Install)

Bằng công nghệ Docker, quá trình setup trên máy chủ VPS Ubuntu đều được tự động hóa. MySQL, Web Server, PHP 8.1 và hẹn giờ Cron Job sẽ tự động bật và liên kết với nhau.

**Bước 1:** SSH vào VPS của bạn với quyền User Root (hoặc dùng `sudo su`).

**Bước 2:** Chạy tuần tự bộ khối lệnh sau:

```bash
# 1. Kéo Code về thư mục cài đặt (/opt/facebook-automation)
git clone https://github.com/azatemplate/cong-cu-facebook.git /opt/facebook-automation

# 2. Di chuyển vào thư mục code
cd /opt/facebook-automation

# 3. Chạy quá trình Cài Đặt Tự Động
bash install.sh
```
Hệ thống sẽ chạy khoảng 2 - 3 phút để Build cấu trúc. Khi màn hình báo chữ **CÀI ĐẶT HOÀN TẤT**, bạn mở Trình Duyệt và truy cập thẳng vào `http://IP_VPS_CUA_BAN` để tải trang.

> **Tài khoản Đăng nhập Hệ Thống mặc định ban đầu:**
> Username: `admin` \
> Password: `admin123`

---

## 🔄 Hướng Dẫn Cập Nhật Code Lên Bản Mới

Mỗi khi bạn có Update hay Sửa lỗi code trên kho `cong-cu-facebook` này của Github, bạn chỉ cần gõ đúng 1 dòng lệnh dưới đây để Update Code cho VPS tự động:

```bash
bash /opt/facebook-automation/update.sh
```

Hệ thống Update.sh sẽ tự động:
1. `git pull` nháy tải bản code thay đổi từ Github.
2. Build và khởi động làm mới lại toàn bộ Service WebServer `docker-compose up -d --build web` mà hoàn toàn KHÔNG là ảnh hưởng hay làm mất kết nối Database mysql của bạn.

---

## ⚙️ Cấu Hình (Setup Tool) Cơ Bản

Sau khi cài đặt xong bạn vô Tool và thiết lập:
1. **Liên kết App ID Facebook:** Tại trang cài đặt (Settings), hãy nhập `FB App ID` và `App Secret` để có thể nhận quyền lấy mã Fanpage. 
2. **Cấu hình AI OpenAI / Gemini:** Nếu cần dùng tính năng Spinner Text tự tạo tiêu đề, hãy nhập API Key vào mục Cấu Hình AI trên Admin Dashboard theo nhu cầu riêng biệt.
3. Các Cron Job đã được dựng **Tất Cả Thành Tự Động**, tool sẽ gọi bài liên tục cứ sau mỗi 1 phút mà không cần thiết lập thủ công Cron Job cho AaPanel/Linux nữa.

---

## 📝 Quản Lý Docker
Nơi chứa toàn bộ dữ liệu MySQL là do Container quản lý. Nếu bạn muốn Backup Database:
* Database lưu gốc vào bộ nhớ đệm Volume Docker trực tiếp qua phân quyền của Docker. Thư mục Mapping vật lý là `./db_data/`. Bất kỳ lúc nào bạn cũng có thể Copy thư mục đó phòng trừ rủi ro.
* Folder chứa file ảnh / Reels là `/uploads/`.
* Xem màn hình Log thời gian thực hệ thống đang chạy ngầm: `docker-compose logs -f`
