# PHP CLI for running Composer, Pest and PHPStan locally: `bin/composer <script>`.
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
