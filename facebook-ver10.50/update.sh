#!/bin/bash
# Kịch bản cập nhật Code tự động 1-Click

APP_DIR="/opt/facebook-automation"

if [ ! -d "$APP_DIR" ]; then
    echo "Hệ thống chưa được cài đặt trong $APP_DIR. Vui lòng chạy install.sh trước!"
    exit 1
fi

echo "============================================="
echo "    BẮT ĐẦU CẬP NHẬT CODE LÊN BẢN MỚI NHẤT   "
echo "============================================="

cd "$APP_DIR"

# 1. Kéo Code về
echo "-> Kéo dữ liệu trên nhánh main..."
git config pull.rebase false
git reset --hard HEAD
git pull origin main

# 2. Refresh lại Docker Container
echo "-> Khởi động lại các thành phần Web/Cron..."
docker-compose up -d --build web

echo ""
echo "============================================="
echo "    CẬP NHẬT HOÀN TẤT, HỆ THỐNG ĐÃ LÊN MỚI!  "
echo "============================================="
