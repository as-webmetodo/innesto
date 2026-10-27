# PHP 8.3 + Apache with only the always-enabled extensions (spec §7.5).
# The official image compiles in pdo_sqlite, mbstring, openssl, curl and more,
# so PHP is rebuilt from the source the image ships, with --disable-all.
FROM php:8.3-apache
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS apache2-dev; \
    rm -rf /usr/local/lib/php/extensions "$PHP_INI_DIR"/conf.d/*; \
    docker-php-source extract; \
    cd /usr/src/php; \
    ./configure \
        --disable-all \
        --disable-cgi \
        --with-apxs2 \
        --with-config-file-path="$PHP_INI_DIR" \
        --with-config-file-scan-dir="$PHP_INI_DIR/conf.d"; \
    make -j"$(nproc)"; \
    make install; \
    cd /; \
    docker-php-source delete; \
    apt-get purge -y --auto-remove $PHPIZE_DEPS apache2-dev; \
    rm -rf /var/lib/apt/lists/*; \
    php -m
