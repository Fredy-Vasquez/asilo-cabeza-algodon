# Contenedor de la aplicación web (PHP 8.2 + Apache) para producción (Azure).
# En XAMPP no se usa: allí Apache y PHP ya vienen instalados.
FROM php:8.2-apache

# Extensión para MySQL/MariaDB; curl, mbstring y openssl ya vienen en la imagen oficial
RUN docker-php-ext-install pdo_mysql \
 && a2enmod headers

# La raíz pública del sitio es la carpeta public/ (src/, config/ y database/ quedan fuera del alcance web)
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && { echo 'ServerTokens Prod'; echo 'ServerSignature Off'; \
      echo 'Header always set X-Content-Type-Options "nosniff"'; \
      echo 'Header always set X-Frame-Options "SAMEORIGIN"'; \
      echo 'Header always set Referrer-Policy "strict-origin-when-cross-origin"'; } > /etc/apache2/conf-available/seguridad.conf \
 && a2enconf seguridad

# PHP de producción: sin mostrar errores al usuario, zona horaria de Guatemala
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && { echo 'date.timezone = America/Guatemala'; echo 'expose_php = Off'; \
      echo 'session.use_strict_mode = 1'; echo 'upload_max_filesize = 8M'; } > "$PHP_INI_DIR/conf.d/asilo.ini"

COPY --chown=www-data:www-data . /var/www/html
EXPOSE 80
