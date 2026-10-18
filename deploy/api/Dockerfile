# ---------------------------------------------------------------------------
# Imagen de la API (Laravel + PHP-FPM + Nginx + supervisor).
# ---------------------------------------------------------------------------
#
# VERSIONES FIJADAS, A PROPOSITO
# -----------------------------
# Ni la de PHP ni la de las extensiones de PostgreSQL usan `latest` ni
# rangos abiertos. Una actualizacion no solicitada en una reconstruccion de
# imagen es como un despliegue que cambia sin que nadie lo decida. Si se quiere
# actualizar, se cambia el numero aqui, a proposito, y se vuelve a correr todo.

# --- Etapa 1: dependencias -------------------------------------------------
FROM composer:2.8.9 AS dependencias

WORKDIR /app

# Primero los archivos de declaracion, y despues el codigo. Asi la capa de
# `composer install` se reutiliza mientras no cambien las dependencias.
COPY composer.json composer.lock* ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --no-interaction

COPY . .

RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# --- Etapa 2: la imagen que corre ------------------------------------------
FROM php:8.3-fpm-bookworm

# `pgsql` y `pdo_pgsql`: la base es PostgreSQL, y RLS no existe en otro motor.
# `bcmath`: el calculo de costos de cancelacion.
# `intl`: las comparaciones de nombres con tildes y acentos, que en espanol no
# son un detalle: "Pérez" y "Perez" NO son el mismo nombre a los ojos de una
# aerolinea, y el sistema tiene que poder distinguirlos.
RUN apt-get update && apt-get install --no-install-recommends -y \
        libpq-dev \
        libicu-dev \
        libzip-dev \
        unzip \
        supervisor \
        nginx \
        libnginx-mod-http-realip \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql bcmath intl opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=dependencias /app /var/www/html

# El usuario de PHP no es root: un proceso web que corre como root convierte
# cualquier fallo de inyeccion en un fallo del servidor entero.
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

COPY docker/php.ini /usr/local/etc/php/conf.d/agencia.ini
COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/agencia.conf
COPY docker/entrypoint.sh /usr/local/bin/entrada

RUN chmod +x /usr/local/bin/entrada \
    && rm -f /etc/nginx/sites-enabled/default \
    && ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

WORKDIR /var/www/html

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrada"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/agencia.conf"]