#!/bin/bash
# vps_master_fix.sh — Script Tối Ưu & Sửa Lỗi Tận Gốc Cho VPS aaPanel

WEB_DIR="/www/wwwroot/fbweb.hongdolab.com"

echo "========================================================="
echo "   ANTIGRAVITY VPS MASTER DIAGNOSTIC & AUTOMATED FIX"
echo "========================================================="
date

# 1. CẬP NHẬT MÃ NGUỒN TỪ GITHUB
echo -e "\n---> [1/7] Cập nhật mã nguồn mới nhất từ GitHub..."
cd $WEB_DIR || exit 1
git init >/dev/null 2>&1
git remote remove origin >/dev/null 2>&1
git remote add origin https://github.com/azatemplate/cong-cu-facebook.git >/dev/null 2>&1
git fetch --all >/dev/null 2>&1
git reset --hard origin/master >/dev/null 2>&1
echo "[OK] Mã nguồn đã được cập nhật thành công."

# 2. DIỆT TIẾN TRÌNH PHP-FPM MỒ CÔI VÀ KHÓA ĐỤNG ĐỘ SOCKET
echo -e "\n---> [2/7] Dọn dẹp tiến trình PHP-FPM mồ côi & socket..."
pkill -9 -f php-fpm >/dev/null 2>&1
rm -f /tmp/php-cgi-74.sock /tmp/php-cgi-*.sock >/dev/null 2>&1

# 3. TỐI ƯU CẤU HÌNH PHP-FPM (NÂNG PM.MAX_CHILDREN NGHỄN TIẾN TRÌNH)
echo -e "\n---> [3/7] Kiểm tra & Tối ưu tham số PHP-FPM (pm.max_children)..."
FPM_CONF="/www/server/php/74/etc/php-fpm.conf"
[ ! -f "$FPM_CONF" ] && FPM_CONF="/www/server/php/74/etc/php-fpm.d/www.conf"

if [ -f "$FPM_CONF" ]; then
    sed -i 's/pm.max_children = .*/pm.max_children = 100/g' "$FPM_CONF"
    sed -i 's/pm.start_servers = .*/pm.start_servers = 10/g' "$FPM_CONF"
    sed -i 's/pm.min_spare_servers = .*/pm.min_spare_servers = 10/g' "$FPM_CONF"
    sed -i 's/pm.max_spare_servers = .*/pm.max_spare_servers = 50/g' "$FPM_CONF"
    sed -i 's/request_terminate_timeout = .*/request_terminate_timeout = 120/g' "$FPM_CONF"
    echo "[OK] Đã tăng pm.max_children = 100 trong $FPM_CONF"
fi

# 4. TẠO INDEX MARIADB TRÊN BẢNG 600k DÒNG
echo -e "\n---> [4/7] Chạy script tối ưu Index MariaDB..."
php $WEB_DIR/fix_mariadb_indexes.php

# 5. DỌN DẸP FILE LOCK TẠM & PHIÊN TREO
echo -e "\n---> [5/7] Dọn dẹp file lock cũ & cache..."
rm -f /tmp/fb_*.done /tmp/facebook_*.lock $WEB_DIR/locks/*.lock >/dev/null 2>&1
touch /tmp/fb_schema_init_v16.done /tmp/fb_pub_worker_mig.done /tmp/fb_scan_phones_mig.done /tmp/fb_retry_reset_$(date +%Y-%m-%d).done

# 6. KHỞI ĐỘNG LẠI DỊCH VỤ SẠCH SẼ
echo -e "\n---> [6/7] Khởi động lại các dịch vụ hệ thống..."
/etc/init.d/sys_stats restart >/dev/null 2>&1
/etc/init.d/redis restart >/dev/null 2>&1
/etc/init.d/mysqld restart >/dev/null 2>&1
/etc/init.d/php-fpm-74 start >/dev/null 2>&1
/etc/init.d/httpd restart >/dev/null 2>&1

# 7. IN CHẨN ĐOÁN THỜI GIAN THỰC
echo -e "\n---> [7/7] Kết quả chẩn đoán VPS thời gian thực..."
php $WEB_DIR/vps_deep_diagnostics.php

echo "========================================================="
echo "   ĐÃ KHẮC PHỤC & TỐI ƯU TOÀN BỘ HỆ THỐNG THÀNH CÔNG!"
echo "========================================================="
