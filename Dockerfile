# syntax=docker/dockerfile:1
FROM php:8.2-apache

# pdo_sqlite ya viene compilado en la imagen oficial; solo activamos módulos de Apache.
RUN a2enmod rewrite headers \
 && a2dissite 000-default \
 && mkdir -p /var/www/app /var/www/data \
 && chown www-data:www-data /var/www/data

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
RUN mkdir -p public/assets/thumbs \
 && chown -R www-data:www-data templates public/assets/thumbs

ENV DB_PATH=/var/www/data/store.sqlite
VOLUME ["/var/www/data", "/var/www/app/templates", "/var/www/app/public/assets/thumbs"]
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD php -r 'exit(@file_get_contents("http://127.0.0.1/healthz.php") === "ok" ? 0 : 1);'

# Apache arranca como root y luego baja a www-data para atender peticiones.
