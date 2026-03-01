FROM wordpress:latest

# Install composer for build script (composer install --no-dev).
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
