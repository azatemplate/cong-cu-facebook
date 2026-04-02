#!/bin/bash
# Kịch bản cài đặt tự động 1-Click trên VPS Linux (Ubuntu/Debian)

echo "============================================="
echo "   TIẾN TRÌNH CÀI ĐẶT FACEBOOK AUTOMATION    "
echo "============================================="
echo ""

# 1. Kiểm tra có quyền root chưa
if [ "$EUID" -ne 0 ]; then
  echo "Vui lòng chạy script này với quyền root (sudo)!"
  exit 1
fi

# 2. Cài đặt Docker và các gói thiết yếu nếu chưa có
echo "-> Kểm tra hoặc Cài đặt Docker & Docker Compose..."
if ! command -v docker &> /dev/null; then
    apt-get update
    apt-get install -y ca-certificates curl gnupg git nano
    curl -fsSL https://get.docker.com -o get-docker.sh
    sh get-docker.sh
    rm get-docker.sh
fi

if ! command -v docker-compose &> /dev/null; then
    apt-get install -y docker-compose-plugin || {
        curl -L "https://github.com/docker/compose/releases/download/v2.24.5/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
        chmod +x /usr/local/bin/docker-compose
    }
fi

# 3. Yêu cầu nhập link kho Git
echo ""
echo "Vui lòng nhập Link GitHub Repository chứa code của bạn"
echo "Ví dụ: https://github.com/ban/cong-cu-facebook.git"
read -p "Link Git: " GIT_REPO

if [ -z "$GIT_REPO" ]; then
    echo "Bạn chưa nhập link Git. Hủy bỏ!"
    exit 1
fi

# 4. Kéo code về VPS
APP_DIR="/opt/facebook-automation"
echo "-> Kéo code mới nhất từ kho lưu trữ về thư mục $APP_DIR..."
if [ -d "$APP_DIR" ]; then
    echo "Thư mục $APP_DIR đã tồn tại. Đang backup dữ liệu thành $APP_DIR.bak..."
    mv "$APP_DIR" "$APP_DIR.bak-$(date +%s)"
fi

git clone "$GIT_REPO" "$APP_DIR"
cd "$APP_DIR"

# 5. Phân quyền và Tạo thư mục thiếu (nếu Git ignore)
mkdir -p uploads
chmod -R 777 uploads

# 6. Khởi chạy Hệ thống
echo "-> Khởi động các Container và Hệ thống tự động mồi Database..."
docker-compose up -d --build

echo ""
echo "================================================="
echo " CÀI ĐẶT HOÀN TẤT & HỆ THỐNG ĐÃ SẴN SÀNG CHẠY! "
echo "================================================="
echo " - Link Truy cập: http://$(curl -s ifconfig.me)"
echo " - Admin Default: admin / admin123"
echo " - Bạn có thể xem trạng thái log qua lệnh: docker-compose logs -f"
echo ""
