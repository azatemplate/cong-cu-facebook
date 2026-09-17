# Hướng Dẫn Kịch Bản Cron Dọn Dẹp Server (Server Cleanup Script)

> **Tần suất khuyến nghị**: 30 phút / 1 lần  
> **Loại Cron trên aaPanel**: *Shell Script*  
> **Mục đích**: Tự động giải phóng ổ đĩa VPS, dọn dẹp file tạm Google Drive, file rác `/tmp`, thùng rác aaPanel và xả bớt log phình to.

---

## 📜 Kịch Bản Bash Dọn Dẹp Server Hoàn Chỉnh

```bash
#!/bin/bash
# =========================================================
# Script Tự Động Dọn Dẹp Tổng Thể Server (Chạy 30 Phút / 1 Lần)
# =========================================================

# 1. Dọn dẹp Thùng rác aaPanel
rm -rf /.Recycle_bin/* 2>/dev/null
rm -rf /www/trash/* 2>/dev/null

# 2. Xóa file rác trong /tmp của hệ thống Linux (Cũ hơn 30 PHÚT)
find /tmp -type f \( -name "*.mp4" -o -name "*.mov" -o -name "*.avi" -o -name "*.tmp" -o -name "*.png" -o -name "*.jpg" -o -name "gdrive_*" -o -name "curl_*" \) -mmin +30 -delete 2>/dev/null

# 3. Xóa file tạm Google Drive trong website fbweb.hongdolab.com (Cũ hơn 30 PHÚT)
find /www/wwwroot/fbweb.hongdolab.com/temp -type f -mmin +30 -delete 2>/dev/null

# 4. Dọn dẹp file tạm của website data.hongdolab.com (Gộp 2 thư mục - Cũ hơn 15 PHÚT)
find /www/wwwroot/data.hongdolab.com/uploads /www/wwwroot/data.hongdolab.com/uploads_tmp -type f -mmin +15 -delete 2>/dev/null

# 5. Làm rỗng các File Log hệ thống & Log đăng bài CHỈ KHI phình to (> 20MB)
find /www/server/data/ -name "*.err" -size +20M -exec truncate -s 0 {} \; 2>/dev/null
find /www/wwwlogs/ -name "*.log" -size +30M -exec truncate -s 0 {} \; 2>/dev/null
find /www/wwwroot/ -name "*.log" -size +20M -exec truncate -s 0 {} \; 2>/dev/null
find /tmp/ -name "fb_*.log" -size +20M -exec truncate -s 0 {} \; 2>/dev/null

# 6. Thu hẹp nhật ký hệ thống Linux về tối đa 50MB
journalctl --vacuum-size=50M 2>/dev/null
```

---

## ⚙️ Các bước cài đặt trên aaPanel
1. Đăng nhập vào **aaPanel**.
2. Chọn menu **Cron**.
3. Cấu hình các mục như sau:
   - **Type of Task**: `Shell Script`
   - **Name of Task**: `Don Dep Server 30 Phut`
   - **Period**: `N Minutes` $\rightarrow$ Nhập `30`
   - **Script Content**: Dán toàn bộ đoạn code ở trên vào.
4. Bấm **Add task**.
