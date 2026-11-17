# syntax=docker/dockerfile:1
FROM node:22-alpine AS dashboard-build
WORKDIR /workspace/dashboard-ui
COPY dashboard-ui/package*.json ./
RUN npm ci
COPY dashboard-ui/ ./
RUN npm run build \
    && test -f dist/index.html \
    && ! grep -q 'src/main.tsx' dist/index.html

FROM php:8.2-apache

ENV PORT=10000
WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libpq-dev \
        libxml2-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        curl gd intl mbstring mysqli pdo pdo_mysql pdo_pgsql pgsql \
        simplexml xml xmlwriter zip \
    && docker-php-ext-install dom \
    && CPPFLAGS="-I/usr/src/php" docker-php-ext-install xmlreader \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

COPY --chown=root:www-data *.php ./
COPY --chown=root:www-data *.js *.css ./
COPY --chown=root:www-data database/ database/
COPY --chown=root:www-data data/ data/
COPY --chown=root:www-data lang/ lang/
COPY --chown=root:www-data public/ public/
COPY --chown=root:www-data resources/ resources/
COPY --chown=root:www-data src/ src/
COPY --chown=root:www-data templates/ templates/
COPY --chown=root:www-data uploads/support/.htaccess uploads/support/.htaccess
COPY --chown=root:www-data vendor/ vendor/
COPY --from=dashboard-build --chown=root:www-data /workspace/dashboard-ui/dist/ /var/www/html/dashboard-ui/dist/

RUN <<'CONFIGURE_APACHE'
set -eu

cat > /etc/apache2/ports.conf <<'APACHE_PORTS'
Listen 80
APACHE_PORTS

cat > /etc/apache2/sites-available/000-default.conf <<'APACHE_SITE'
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot /var/www/html
    DirectoryIndex index.php index.html

    <Directory /var/www/html>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    Alias /dashboard-ui/ /var/www/html/dashboard-ui/dist/

    <Directory /var/www/html/dashboard-ui/dist>
        Options FollowSymLinks
        AllowOverride None
        Require all granted
        RewriteEngine On
        RewriteBase /dashboard-ui/
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^ index.html [L]
    </Directory>

    <DirectoryMatch "^/var/www/html/(?:database|resources/views|src|templates|tests|tools|vendor)(?:/|$)">
        Require all denied
    </DirectoryMatch>

    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>

    ErrorLog /proc/self/fd/2
    CustomLog /proc/self/fd/1 combined
</VirtualHost>
APACHE_SITE
CONFIGURE_APACHE

RUN chown root:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 0750 {} + \
    && find /var/www/html -type f -exec chmod 0640 {} + \
    && mkdir -p /var/www/storage /var/www/html/uploads/support /var/www/html/releases \
    && chown -R www-data:www-data /var/www/storage /var/www/html/uploads /var/www/html/releases \
    && chmod 0700 /var/www/storage \
    && find /var/www/html/uploads /var/www/html/releases -type d -exec chmod 0770 {} + \
    && find /var/www/html/uploads /var/www/html/releases -type f -exec chmod 0660 {} +

RUN <<'CREATE_START_SCRIPT'
set -eu

cat > /usr/local/bin/start-app <<'START_SCRIPT'
#!/bin/sh
set -eu

port="${PORT:-10000}"
case "$port" in
    *[!0-9]*)
        echo "PORT must be numeric" >&2
        exit 1
        ;;
esac

sed -i "s/^Listen .*/Listen ${port}/" /etc/apache2/ports.conf
sed -i "s/^<VirtualHost .*>\$/<VirtualHost *:${port}>/" \
    /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
START_SCRIPT

chmod 0755 /usr/local/bin/start-app
CREATE_START_SCRIPT

EXPOSE 10000

CMD ["/usr/local/bin/start-app"]