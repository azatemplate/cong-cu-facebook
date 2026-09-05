FROM php:8.1-apache

# Cài đặt các thư viện hệ thống cần thiết và tiện ích
RUN apt-get update && apt-get install -y \
    cron \
    nano \
    curl \
    zip \
    unzip \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libwebp-dev \
    && rm -rf /var/lib/apt/lists/*

# Cấu hình và cài đặt PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install gd pdo pdo_mysql opcache

# Bật Apache mod_rewrite
RUN a2enmod rewrite

# Cấu hình PHP (VD: mảng upload)
RUN echo "upload_max_filesize = 1200M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 1200M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 2048M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 3600" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "date.timezone = Asia/Ho_Chi_Minh" >> /usr/local/etc/php/conf.d/uploads.ini

# Copy entrypoint và crontab vào
COPY docker/crontab /etc/cron.d/facebook_cron
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh && \
    chmod 0644 /etc/cron.d/facebook_cron && \
    crontab /etc/cron.d/facebook_cron

WORKDIR /var/www/html

CMD ["/usr/local/bin/entrypoint.sh"]
