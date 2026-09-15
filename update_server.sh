#!/bin/bash
# Kịch bản cập nhật Code tự động 1-Click dành cho VPS Server (Native deployment)

APP_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "============================================="
echo "    BẮT ĐẦU CẬP NHẬT CODE LÊN BẢN MỚI NHẤT   "
echo "============================================="

# Kiểm tra thư mục gốc
if [ ! -d "$APP_DIR" ]; then
    echo "Lỗi: Không tìm thấy thư mục $APP_DIR. Vui lòng kiểm tra lại đường dẫn."
    exit 1
fi

cd "$APP_DIR" || exit

# Đảm bảo hệ thống sử dụng Git
if [ ! -d ".git" ]; then
    echo "Lỗi: Thư mục chưa được liên kết với Github (chưa init Git)."
    echo "Để cài đặt lần đầu, vui lòng xóa code cũ và git clone lại mã nguồn!"
    exit 1
fi

echo "-> Kéo dữ liệu mới nhất từ nhánh main..."
# Xóa bỏ các thay đổi không mong muốn (conflict)
git checkout -- .
git clean -fd
git config pull.rebase false
git pull origin main

echo "-> Cấp quyền thư mục an toàn (chống lỗi Upload/Session)..."
chown -R www:www "$APP_DIR"
find "$APP_DIR" -type f -exec chmod 644 {} \;
find "$APP_DIR" -type d -exec chmod 755 {} \;

# Thư mục bắt buộc phải ghi được
mkdir -p "$APP_DIR/uploads" "$APP_DIR/uploads/sessions" "$APP_DIR/uploads/rate_limits" "$APP_DIR/uploads/cache"
chmod -R 777 "$APP_DIR/uploads"

# Đồng bộ tự động tệp upload_video.php sang web data.hongdolab.com nếu có trên aaPanel
if [ -d "/www/wwwroot/data.hongdolab.com" ]; then
    echo "-> Đồng bộ mã nguồn upload_video.php sang data.hongdolab.com..."
    mkdir -p "/www/wwwroot/data.hongdolab.com/api" "/www/wwwroot/data.hongdolab.com/uploads"
    chmod 777 "/www/wwwroot/data.hongdolab.com/uploads"
    cp -f "$APP_DIR/data/api/upload_video.php" "/www/wwwroot/data.hongdolab.com/api/upload_video.php"
    chmod 755 "/www/wwwroot/data.hongdolab.com/api/upload_video.php"
    chown -R www:www "/www/wwwroot/data.hongdolab.com"
fi

echo ""
echo "============================================="
echo "    CẬP NHẬT HOÀN TẤT, HỆ THỐNG ĐÃ LÊN MỚI!  "
echo "============================================="
