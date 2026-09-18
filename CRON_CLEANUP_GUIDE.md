# Hướng Dẫn Kịch Bản Cron Dọn Dẹp Server (Server Disk Cleanup Script)

> **Tần suất khuyến nghị**: 5 phút / 1 lần  
> **Loại Cron trên aaPanel**: *Shell Script*  
> **Mục đích**: Tự động giải phóng ổ đĩa VPS siêu tốc (xóa video/ảnh/chunk tạm cũ hơn 5 phút), gọi `cron/cleanup.php`, dọn dẹp thùng rác aaPanel và thu hẹp file log phình to.

---

## 📜 Kịch Bản Bash Dọn Dẹp Server Hoàn Chỉnh (Chạy 5 Phút / 1 Lần)

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
find /www/wwwroot/fbweb1.hongdolab.com/temp /www/wwwroot/fbweb1.hongdolab.com/uploads/tmp -type f -mmin +5 -delete 2>/dev/null

# 4. Gọi php cleanup.php dọn dẹp ngầm
php /www/wwwroot/fbweb1.hongdolab.com/cron/cleanup.php >/dev/null 2>&1

# 5. Làm rỗng File Log phình to (> 10MB)
find /www/server/data/ -name "*.err" -size +10M -exec truncate -s 0 {} \; 2>/dev/null
find /www/wwwlogs/ -name "*.log" -size +10M -exec truncate -s 0 {} \; 2>/dev/null
find /www/wwwroot/ -name "*.log" -size +10M -exec truncate -s 0 {} \; 2>/dev/null
find /tmp/ -name "fb_*.log" -size +10M -exec truncate -s 0 {} \; 2>/dev/null

# 6. Thu hẹp nhật ký hệ thống Linux về 50MB
journalctl --vacuum-size=50M 2>/dev/null
```

---

## ⚙️ Các bước cài đặt trên aaPanel
1. Đăng nhập vào **aaPanel**.
2. Chọn menu **Cron**.
3. Cấu hình các mục như sau:
   - **Type of Task**: `Shell Script`
   - **Name of Task**: `Don Dep Server 5 Phut`
   - **Period**: `N Minutes` $\rightarrow$ Nhập `5`
   - **Script Content**: Dán toàn bộ đoạn code ở trên vào.
4. Bấm **Add task**.
