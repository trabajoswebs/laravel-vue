FROM sail-8.4/app

# Optimizadores de imágenes (binarios nativos)
RUN apt-get update && apt-get install -y --no-install-recommends \
    jpegoptim optipng pngquant gifsicle webp libwebp-dev libjpeg-turbo-progs \
    && rm -rf /var/lib/apt/lists/*

# Extensiones PHP para imágenes (sin docker-php-ext-install)
# Nota: usamos paquetes del repo de Ondřej: php8.4-*
RUN apt-get update && apt-get install -y --no-install-recommends \
    imagemagick libmagickwand-dev \
    php8.4-exif php8.4-gd php8.4-imagick \
    && rm -rf /var/lib/apt/lists/*

COPY docker/8.4/nginx/default.conf /etc/nginx/conf.d/default.conf
