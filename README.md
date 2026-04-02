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
- Một đoạn mã **GitHub Personal Access Token** để VPS có tải code từ Repo ẩn (Private) của bạn.

---

## 🚀 Hướng Dẫn Cài Đặt (1 Lệnh Duy Nhất)

Vào máy chủ (VPS) của bạn bằng tài khoản `root`, copy và dán nguyên xi dòng lệnh sau (Hãy thay chỗ **YOUR_GITHUB_TOKEN** bằng Token của bạn).

```bash
wget https://raw.githubusercontent.com/azatemplate/cong-cu-facebook/main/install.sh --header "Authorization: token YOUR_GITHUB_TOKEN" && bash install.sh
```

**Quá trình tương tác khi cấu hình (Interactive Console):**
1. Màn hình sẽ nhắc bạn nhập **Tên miền (Domain)**: Bạn có thể điền (Ví dụ: tool.domain.com). Nếu có điền, tool sẽ tải Caddy Reverse Proxy & thiết lập tự động chững chỉ xanh SSL. Nếu không muốn xài tên miền, nhấn Enter bỏ trống.
2. Nhắc nhập lại **GitHub Token**: Chép dán mã token nhằm tải toàn bộ lõi code về máy tự động.
3. Chờ 3-4 phút cho tới khi màn hình hiển thị lời chào **CÀI ĐẶT HOÀN TẤT & HỆ THỐNG ĐÃ SẴN SÀNG CHẠY!**.

> **Tài khoản Đăng nhập Hệ Thống mặc định ban đầu:**
> Username: `admin` \
> Password: `admin123`

---

## 🔄 Cập Nhật Hệ Thống

Bất cứ lúc nào bạn sửa code (trên local / Github) và muốn Server áp dụng bản mới theo nhánh Main, trên VPS chỉ việc gõ gõ:

```bash
bash /opt/facebook-automation/update.sh
```

Hệ thống sẽ kéo Git tự động và Restart trơn tru cực nhanh mà không làm hỏng Database đang có.

---

## ⚙️ Cấu Hình (Setup Tool) Cơ Bản Bên Trong

Sau khi vào được Tool ở đường dẫn Web, hãy tinh chỉnh:
1. **Thiết lập Nhóm quyền (App ID Facebook):** Tại trang cài đặt (Settings), hãy nhập `FB App ID` và `App Secret`.
2. **Cấu hình AI OpenAI / Gemini:** Nếu cần dùng tính năng tạo Caption (Spinner) ngẫu nhiên, hãy nhập API Key vào Cấu Hình AI trên Admin Dashboard.
3. Các tiến trình Background Worker (CronJob lặp mỗi 1 phút để test bài mới) chạy ngầm hoàn toàn bên trong Docker. Bạn không cần làm gì thêm ở ngoài Server Ubuntu!

---

## 📝 Quản Lý Docker Root
* Dữ liệu MySQL Database: Mapping tại `/opt/facebook-automation/db_data/`. Backup thư mục này là đảm bảo an toàn tuyệt đối.
* Media (Ảnh/Video gốc tải lên máy hoặc TikTok lưu trữ): Mapping tại `/opt/facebook-automation/uploads/`.
* Xem màn hình theo dõi Log để Debug: Gõ `cd /opt/facebook-automation && docker-compose logs -f`
