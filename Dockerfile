FROM sail-8.4/app

COPY docker/scripts/ensure-clamav-db.sh /usr/local/bin/ensure-clamav-db
COPY docker/scripts/clamdscan-wrapper.sh /usr/local/bin/clamdscan-wrapper.sh
COPY docker/8.4/start-container /usr/local/bin/start-container
COPY docker/8.4/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
RUN chmod +x /usr/local/bin/ensure-clamav-db /usr/local/bin/start-container /usr/local/bin/clamdscan-wrapper.sh

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

# Antivirus (ClamAV) para escaneo de uploads (descarga de firmas en arranque via start-container)
RUN apt-get update && apt-get install -y --no-install-recommends \
    clamav clamav-daemon socat \
    && ln -sf /usr/local/bin/clamdscan-wrapper.sh /usr/bin/clamdscan \
    && mkdir -p /run/clamav /var/log/clamav \
    && chown -R clamav:clamav /run/clamav /var/log/clamav \
    && rm -rf /var/lib/apt/lists/*

COPY docker/8.4/nginx/default.conf /etc/nginx/conf.d/default.conf
