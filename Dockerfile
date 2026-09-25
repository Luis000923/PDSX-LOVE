# syntax=docker/dockerfile:1
FROM php:8.2-apache

# pdo_mysql NO viene en la imagen oficial (solo pdo_sqlite): se compila aquí contra mysqlnd,
# que ya está incluido, así que no hacen falta librerías de cliente. Además, módulos de Apache.
# zip: el panel admin instala plantillas PHP subidas como .zip (PhpTemplate).
# gd (jpeg/png/webp): las fotos que suben los usuarios se vuelven a codificar y redimensionar (ImageStore).
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev libpng-dev libjpeg-dev libwebp-dev \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql zip gd \
 && a2enmod rewrite headers \
 && a2dissite 000-default \
 && mkdir -p /var/www/app

COPY docker/apache.conf /etc/apache2/sites-available/app.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
RUN a2ensite app

WORKDIR /var/www/app
COPY config ./config
COPY database/schema.sql ./database/schema.sql
COPY public ./public
COPY src ./src
COPY bin ./bin
COPY templates ./templates

# El panel admin edita plantillas y sube miniaturas en tiempo de ejecución: esas dos rutas
# son volúmenes (Docker las siembra con lo de la imagen la primera vez) y deben ser de www-data.
RUN mkdir -p public/assets/thumbs public/assets/tpl templates/php public/uploads/sites storage/user_html \
 && chown -R www-data:www-data templates public/assets/thumbs public/assets/tpl public/uploads storage

# La BD ya no vive en un volumen local: es un servidor MySQL externo (DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD).
# uploads = fotos de los usuarios (públicas, sin ejecución); storage = HTML propio de los usuarios (FUERA del webroot).
VOLUME ["/var/www/app/templates", "/var/www/app/public/assets/thumbs", "/var/www/app/public/assets/tpl", "/var/www/app/public/uploads", "/var/www/app/storage"]
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD php -r 'exit(@file_get_contents("http://127.0.0.1/healthz.php") === "ok" ? 0 : 1);'

# Apache arranca como root y luego baja a www-data para atender peticiones.
