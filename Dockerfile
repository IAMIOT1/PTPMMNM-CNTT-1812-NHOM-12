FROM php:8.2-apache

# Cài đặt extension PostgreSQL cho PHP
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql

# Cấu hình thư mục chạy web của Apache (nếu mã nguồn nằm trong thư mục Ma_nguon)
ENV APACHE_DOCUMENT_ROOT /var/www/html/Ma_nguon
RUN sed -ri -s 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -s 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf

# Copy toàn bộ mã nguồn vào container
COPY . /var/www/html/

# Cho phép rewrite URL nếu cần
RUN a2enmod rewrite