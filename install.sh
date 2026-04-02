#!/bin/bash
# Kịch bản cài đặt tự động 1-Click trên VPS Linux (Ubuntu/Debian) - Version Pro

echo "============================================="
echo "   TIẾN TRÌNH CÀI ĐẶT FACEBOOK AUTOMATION    "
echo "============================================="
echo ""

# 1. Kiểm tra có quyền root chưa
if [ "$EUID" -ne 0 ]; then
  echo "Vui lòng chạy script này với quyền root (sudo)!"
  exit 1
fi

# 2. Thu thập thông tin từ người dùng
read -p "Nhập Tên Miền của bạn (VD: facebook.com) hoặc để trống nếu dùng IP: " DOMAIN_NAME
read -p "Nhập GitHub Personal Access Token của bạn (Để nhân bản kho Private): " GIT_TOKEN
echo ""

if [ -z "$GIT_TOKEN" ]; then
    echo "Lỗi: Bạn bắt buộc phải nhập GitHub Token để tải code!"
    exit 1
fi

# Link repo cố định của bạn (thay token vào URL)
GIT_REPO="https://${GIT_TOKEN}@github.com/azatemplate/cong-cu-facebook.git"

# 3. Cài đặt Docker và các gói thiết yếu nếu chưa có
echo "-> Kểm tra hoặc Cài đặt Docker..."
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

# 4. Kéo code về VPS
APP_DIR="/opt/facebook-automation"
echo "-> Kéo code mới nhất từ kho lưu trữ về thư mục $APP_DIR..."
if [ -d "$APP_DIR" ]; then
    echo "Thư mục $APP_DIR đã tồn tại. Xóa cache cài đè..."
    rm -rf "$APP_DIR"
fi

git clone "$GIT_REPO" "$APP_DIR"
cd "$APP_DIR"

# 5. Phân quyền và Tạo thư mục thiếu
mkdir -p uploads db_data
chmod -R 777 uploads

# 6. Thiết lập Caddy & Domain nếu có
if [ -n "$DOMAIN_NAME" ]; then
    echo "-> Cấu hình Tên Miền ($DOMAIN_NAME) với giao thức SSL..."
    
    # Sinh file Caddyfile để tự động cấp phát SSL miễn phí
    cat <<EOF > Caddyfile
$DOMAIN_NAME {
    reverse_proxy web:80
}
EOF
    
    # Sửa lại docker-compose.yml để thay Caddy làm router chính
    cat <<EOF > docker-compose-override.yml
version: '3.8'
services:
  caddy:
    image: caddy:2-alpine
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile
      - caddy_data:/data
      - caddy_config:/config
    depends_on:
      - web
  
  web:
    ports:
      - "80" # Bỏ map port 80 trực tiếp ra ngoài máy chủ, để caddy đứng ra hứng

volumes:
  caddy_data:
  caddy_config:
EOF
else
    # Không dùng domain
    echo "-> Đang chạy chế độ IP Trực tiếp (Port 80)..."
fi

# 7. Khởi chạy Hệ thống
echo "-> Khởi động các Container và Hệ thống tự động mồi Database..."
if [ -n "$DOMAIN_NAME" ]; then
    docker-compose -f docker-compose.yml -f docker-compose-override.yml up -d --build
else
    docker-compose up -d --build
fi

echo ""
echo "================================================="
echo " CÀI ĐẶT HOÀN TẤT & HỆ THỐNG ĐÃ SẴN SÀNG CHẠY! "
echo "================================================="
if [ -n "$DOMAIN_NAME" ]; then
    echo " - Link Truy cập: https://$DOMAIN_NAME"
else
    echo " - Link Truy cập: http://$(curl -s ifconfig.me)"
fi
echo " - Admin Default: admin / admin123"
echo "================================================="
echo ""
