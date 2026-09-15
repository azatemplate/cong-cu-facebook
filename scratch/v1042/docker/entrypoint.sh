#!/bin/bash
set -e

# Đảm bảo quyền sở hữu thư mục WWW
chown -R www-data:www-data /var/www/html/uploads 2>/dev/null || true

# Khởi động dịch vụ Cron Job ngầm của Ubuntu
service cron start

# Khởi chạy Apache ở chế độ Foreground để giữ container sống
docker-php-entrypoint apache2-foreground
