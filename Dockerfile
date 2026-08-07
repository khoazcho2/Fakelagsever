FROM php:8.2-apache

# pdo đã có sẵn trong image php:8.2-apache, chỉ cần cài thêm pdo_sqlite
RUN docker-php-ext-install pdo_sqlite

# Copy toàn bộ mã nguồn vào thư mục web root của Apache
COPY . /var/www/html/

# Tạo thư mục data (chứa file db + config) và cấp quyền ghi
RUN mkdir -p /var/www/html/data && chown -R www-data:www-data /var/www/html/data

# Render cấp PORT qua biến môi trường, Apache mặc định nghe cổng 80
ENV PORT=80
EXPOSE 80
