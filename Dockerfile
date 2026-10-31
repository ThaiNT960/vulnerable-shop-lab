FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite \
    && a2enmod rewrite \
    && mkdir -p /var/lib/shop-lab /var/www/html/uploads \
    && chown -R www-data:www-data /var/lib/shop-lab /var/www/html/uploads \
    && rm -rf /var/lib/apt/lists/*

COPY apache.conf /etc/apache2/sites-available/000-default.conf
COPY app/ /var/www/shop-app/
COPY public/ /var/www/html/
RUN chown -R root:root /var/www/shop-app /var/www/html \
    && chown www-data:www-data /var/www/html/uploads \
    && chmod 775 /var/www/html/uploads

EXPOSE 80
