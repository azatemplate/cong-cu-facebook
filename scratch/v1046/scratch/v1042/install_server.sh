#!/bin/bash
# Kịch bản Cài đặt 1-Click dành cho VPS đã có sẵn Panel (aaPanel, CyberPanel, DirectAdmin) hoặc Native Nginx/Apache.

echo "======================================================="
echo "   TIẾN TRÌNH CÀI ĐẶT FACEBOOK AUTOMATION (NATIVE)     "
echo "======================================================="
echo ""

# 1. Nhập Tên Miền
read -p "Nhập Tên Miền bạn đã Add Site trên Panel (VD: app.domain.com): " DOMAIN_NAME
if [ -z "$DOMAIN_NAME" ]; then
    echo "Tên miền không được để trống!"
    exit 1
fi

APP_DIR="/www/wwwroot/$DOMAIN_NAME"

# 2. Kiểm tra thư mục gốc của aaPanel
if [ ! -d "$APP_DIR" ]; then
    echo "Thư mục $APP_DIR chưa tồn tại."
    read -p "Bạn có muốn tạo mới thư mục này không? (y/n): " confirm_create
    if [ "$confirm_create" = "y" ]; then
        mkdir -p "$APP_DIR"
    else
        echo "Vui lòng Add Site trên giao diện aaPanel trước khi chạy Script!"
        exit 1
    fi
fi

echo "-> Làm sạch thư mục tạm..."
cd "$APP_DIR" || exit
# Tránh xoá nhầm file config/ẩn do aapanel sinh ra
rm -rf index.html 404.html default.html

# 3. Kéo Code về
echo "-> Kéo Source Code từ Github nhánh main..."
GIT_REPO="https://github.com/azatemplate/cong-cu-facebook.git"
git init
git remote add origin "$GIT_REPO"
git fetch --all
git reset --hard origin/main
git pull origin main

# 4. Phân Quyền Thư Mục
echo "-> Cấu hình quyền ghi đọc chuẩn bảo mật (0755/0644)..."
chown -R www:www "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 755 {} \;
find "$APP_DIR" -type f -exec chmod 644 {} \;

# Thư mục Media cần quyền 777 để thao tác file tạm
mkdir -p "$APP_DIR/uploads" "$APP_DIR/uploads/sessions" "$APP_DIR/uploads/rate_limits" "$APP_DIR/uploads/cache"
chmod -R 777 "$APP_DIR/uploads"
chown -R www:www "$APP_DIR/uploads"

# 5. Thiết lập Database & Sinh file bảo mật (.env)
echo "-> Thiết lập Kết Nối Lưu Trữ Dữ Liệu (MySQL)..."
echo "LƯU Ý: Bạn cần tạo sẵn 1 Database trống trên aaPanel/cPanel trước."
read -p " - Nhập Database Name (Tên CSDL): " DB_NAME
read -p " - Nhập Database User (Tên Người dùng): " DB_USER
read -s -p " - Nhập Database Password (Mật khẩu - Bị ẩn khi gõ): " DB_PASS
echo ""

if [ -n "$DB_NAME" ] && [ -n "$DB_USER" ] && [ -n "$DB_PASS" ]; then
    echo "-> Đang khởi tạo file bảo mật .env..."
    cp .env.example .env
    sed -i "s/DB_NAME=.*/DB_NAME=$DB_NAME/g" .env
    sed -i "s/DB_USER=.*/DB_USER=$DB_USER/g" .env
    sed -i "s/DB_PASS=.*/DB_PASS=$DB_PASS/g" .env
    
    echo "-> Đang tự động nạp dữ liệu (Import SQL) vào hệ thống..."
    if command -v mysql &> /dev/null; then
        mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < facebooksever.sql
        if [ $? -eq 0 ]; then
            echo "   [V] Import Dữ liệu Database THÀNH CÔNG!"
            # Xoá file sql sau khi cài xong cho an toàn
            rm -f facebooksever.sql
        else
            echo "   [X] Lỗi Import dữ liệu. Vui lòng tự Import file facebooksever.sql thủ công."
        fi
    else
        echo "   [X] Không tìm thấy lệnh 'mysql' trên máy chủ. Bạn cần Import thủ công."
    fi
else
    echo "-> Bỏ qua cài đặt tự động Database. Bạn phải cấu hình thủ công vào file .env!"
fi

# 6. Cài đặt CronJob Tự Động
echo "-> Thiết lập tiến trình Cronjob chạy nền (Mỗi 1 phút)..."
CRON_PUBLISH="* * * * * php $APP_DIR/cron/start_publish.php >> /tmp/fb_publish.log 2>&1"
CRON_COMMENT="* * * * * php $APP_DIR/cron/start_comment.php >> /tmp/fb_comment.log 2>&1"
CRON_INSIGHTS="*/5 * * * * php $APP_DIR/cron/comment_insights_worker.php >> /tmp/fb_comment_insights.log 2>&1"

# Kiểm tra xem cronjob đã tồn tại chưa để tránh add trùng
(crontab -l 2>/dev/null | grep -F "$APP_DIR/cron/start_publish.php") || (crontab -l 2>/dev/null; echo "$CRON_PUBLISH") | crontab -
(crontab -l 2>/dev/null | grep -F "$APP_DIR/cron/start_comment.php") || (crontab -l 2>/dev/null; echo "$CRON_COMMENT") | crontab -
(crontab -l 2>/dev/null | grep -F "$APP_DIR/cron/comment_insights_worker.php") || (crontab -l 2>/dev/null; echo "$CRON_INSIGHTS") | crontab -

echo ""
echo "======================================================="
echo " CÀI ĐẶT HOÀN TẤT, HỆ THỐNG ĐÃ SẴN SÀNG TRÊN NATIVE!  "
echo "======================================================="
echo " Tên miền: https://$DOMAIN_NAME"
echo " Đường dẫn App: $APP_DIR"
echo " Cronjob: Đã thêm tự động vào crontab của máy chủ."
echo "-------------------------------------------------------"
echo " Truy cập vào web và đăng nhập Admin mặc định: admin / admin123"
echo " (Đổi mật khẩu ngay sau khi đăng nhập thành công)"
echo "======================================================="
echo ""
