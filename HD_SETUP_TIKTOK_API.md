# HƯỚNG DẪN THIẾT LẬP CHI TIẾT API TIKTOK KHÔNG WATERMARK TỰ CHẠY

Tài liệu này hướng dẫn chi tiết cách cài đặt, tích hợp, tối ưu hóa và xử lý sự cố cho API tải video TikTok không watermark (chạy bằng Python FastAPI) để tích hợp vào các hệ thống PHP Automation khác.

---

## 1. Kiến Trúc Hoạt Động và Cơ Chế Tự Động Dự Phòng (Fallback)
* **API Chính (Local Singapore Server API):** API Python gửi truy vấn trực tiếp đến cổng API di động chính thức của TikTok đặt tại khu vực Singapore (`api22-normal-c-alisg.tiktokv.com`). Phương thức này chạy siêu nhanh (~0.3 giây) và không cần giả lập trình duyệt (không tốn tài nguyên và không sợ lỗi Playwright/Selenium).
* **Cơ Chế Dự Phòng (TikWM Fallback):** Nếu IP của VPS bị TikTok chặn tạm thời (lỗi `429 Too Many Requests` hoặc `400`), API Python sẽ tự động chuyển hướng yêu cầu sang cổng API công cộng của `TikWM` để phân tích.
* **Tối Ưu Thời Gian Chờ (Timeout Optimization):** Thời gian chờ tối đa cho cổng chính được đặt là **3 giây**. Nếu quá 3 giây mà cổng chính bị chặn hoặc treo, hệ thống sẽ lập tức chuyển hướng sang TikWM để tránh bị nghẽn (Thời gian phản hồi tổng cộng chỉ mất từ 2-4 giây ngay cả khi chạy dự phòng).

---

## 2. Danh Sách Các File Cần Thiết trong Mã Nguồn
1. **`api/api.py`**: Mã nguồn Python FastAPI khởi tạo API server nội bộ (cổng `8000`).
2. **`actions/video.php`**: Xử lý request từ người dùng trên giao diện Web, gọi đến API Python cổng `8000` và trả kết quả dạng JSON.
3. **`cron/publish_worker.php`**: Chạy tác vụ cronjob đăng bài tự động ngầm, gọi API Python để tải video sạch trước khi đẩy lên Page Facebook.
4. **`settings.php`**: Quản trị cấu hình để nhập và lưu địa chỉ API (`http://127.0.0.1:8000`).

---

## 3. Các Bước Cài Đặt Trên VPS Mới (Linux Ubuntu/Debian)

### Bước 3.1: Upload file lên VPS
Đặt thư mục `api` chứa file `api.py` vào thư mục gốc của trang web trên VPS.
Ví dụ đường dẫn đúng:
```text
/www/wwwroot/app.hongvippro.com/api/api.py
```

### Bước 3.2: Cài đặt Môi trường ảo Python (Venv)
Mở terminal SSH của VPS, di chuyển (`cd`) vào thư mục gốc chứa web và chạy:

```bash
# 1. Cập nhật gói phần mềm của hệ thống
apt update

# 2. Cài đặt python venv và pip (Bắt buộc phải có để tạo môi trường ảo)
# (Thay thế 3.10 bằng phiên bản python đang chạy trên VPS của bạn, ví dụ python3-venv hoặc python3.11-venv)
apt install python3-venv python3-pip -y

# 3. Tạo thư mục môi trường ảo tên là venv
python3 -m venv venv

# 4. Kích hoạt môi trường ảo venv
source venv/bin/activate

# 5. Cài đặt các thư viện cần thiết cho API
pip install fastapi uvicorn httpx
```

### Bước 3.3: Khởi chạy API chạy nền

**Cách 1: Khởi chạy bằng `nohup` (Khuyên dùng - Nhanh nhất, không cần cài đặt thêm công cụ):**

Cần di chuyển vào thư mục dự án trước:
```bash
cd /www/wwwroot/app.hongvippro.com/
```

Sau đó tắt tiến trình cũ và khởi chạy lại bằng môi trường ảo venv chuẩn:
```bash
# 3. Tắt tiến trình cũ
pkill -9 -f api.py

# 4. Khởi chạy lại API trong nền bằng venv chuẩn
nohup venv/bin/python api/api.py > api.log 2>&1 &
```
* Tiến trình sẽ chạy ẩn dưới nền và tự động ghi log hoạt động vào file `api.log`.
* Kiểm tra trạng thái hoạt động: `cat api.log` hoặc `ps aux | grep api.py`.

**Cách 2: Khởi chạy bằng `PM2` (Nếu VPS đã cài đặt sẵn Node.js/NPM):**
```bash
# Cài đặt PM2 toàn cục (nếu chưa có)
npm install pm2 -g

# Khởi chạy API
pm2 start "venv/bin/python api/api.py" --name "tiktok-api"

# Lưu danh sách tiến trình tự khởi động lại khi reboot VPS
pm2 save
pm2 startup
```

---

## 4. Tích Hợp Vào Mã Nguồn PHP

1. Truy cập vào trang quản trị cấu hình hệ thống (`settings.php`).
2. Nhập địa chỉ local của API Python vào ô **TikTok Custom API URL**:
   ```text
   http://127.0.0.1:8000
   ```
3. Nhấn **Lưu Tùy Chỉnh**. Cấu hình sẽ được lưu vào bảng `system_settings` trong cơ sở dữ liệu với khóa `tiktok_api_url`.

---

## 5. Các Lỗi Thường Gặp & Hướng Dẫn Khắc Phục Triệt Để

### Lỗi 1: `Errno 2: No such file or directory` khi chạy nohup
* **Triệu chứng:** Log báo lỗi `venv/bin/python: can't open file 'api/api.py'`.
* **Nguyên nhân:** Bạn đang đứng ở thư mục khác (thường là thư mục mặc định `/root`) khi chạy lệnh.
* **Cách sửa:** Gõ lệnh `cd` chuyển vào đúng thư mục gốc chứa thư mục `api` trước khi chạy (ví dụ: `cd /www/wwwroot/app.hongvippro.com`).

### Lỗi 2: Lỗi 404 Not Found hiển thị trang Nginx đen trắng
* **Nguyên nhân 1:** Do file được upload/tạo bằng tài khoản `root`, Nginx chạy bằng tài khoản `www` không có quyền đọc.
  * **Cách sửa:** Chạy lệnh phân quyền lại toàn bộ thư mục:
    ```bash
    chown -R www:www /www/wwwroot/ten_domain_cua_ban
    chmod -R 755 /www/wwwroot/ten_domain_cua_ban
    ```
* **Nguyên nhân 2 (Cơ chế Intercept lỗi của Nginx):** Trong file `actions/video.php` cũ, khi API bị lỗi, PHP trả về mã trạng thái HTTP là `404`. Nginx có cấu hình `fastcgi_intercept_errors on` sẽ tự động chặn mã lỗi này để hiện trang HTML 404 mặc định.
  * **Cách sửa:** Mã nguồn mới đã được đổi mã trạng thái trả về thành `200` kèm thông báo lỗi chi tiết dạng JSON (`{"code":-1, "msg":"..."}`). Điều này giúp Nginx bỏ qua và hiển thị chính xác lỗi từ PHP.

### Lỗi 3: Cổng 8000 đã bị chiếm dụng (Không thể khởi động API mới)
* **Triệu chứng:** Chạy lệnh khởi động nhưng API mới không chạy, hoặc log báo lỗi `Address already in use`.
* **Cách sửa:** Bạn cần tắt tiến trình cũ đang chiếm cổng `8000` trước khi chạy lệnh khởi chạy mới:
  ```bash
  kill -9 $(lsof -t -i:8000) 2>/dev/null || fuser -k 8000/tcp 2>/dev/null
  ```

### Lỗi 4: `UnicodeEncodeError: 'charmap' codec can't encode...` (Khi chạy trên Windows Server)
* **Nguyên nhân:** Bảng mã mặc định của PowerShell/CMD trên Windows Server không hỗ trợ ký tự tiếng Trung/Unicode lấy từ TikTok nên bị crash khi ghi log console.
* **Cách sửa:** Chạy Python kèm cờ kích hoạt UTF-8:
  ```powershell
  $env:PYTHONIOENCODING="utf-8"
  python -X utf8 api/api.py
  ```

---

## 6. Tài Liệu Tham Khảo / Nguồn Gốc
* Dự án được xây dựng và tham khảo dựa trên mã nguồn mở: [Douyin_TikTok_Download_API](https://github.com/Evil0ctal/Douyin_TikTok_Download_API)
