#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

CACHE_FILE="bootstrap/cache/config.testing.php"

# Limpia siempre el cache “testing” y, por seguridad, el config.php normal si alguien lo generó a mano.
cleanup() {
  rm -f "$CACHE_FILE"
  rm -f "bootstrap/cache/config.php"
}
trap cleanup EXIT

./vendor/bin/sail run bash -lc "
set -euo pipefail
# Dentro del contenedor, el proyecto vive en /var/www/html (no en la ruta del host).
cd /var/www/html

CACHE_FILE='$CACHE_FILE'
rm -f \"\$CACHE_FILE\"

# 1) Cargar env de phpunit.xml(.dist) ANTES de config:cache (porque con config cache Laravel no lee .env)
PHPUNIT_XML='phpunit.xml'
[[ -f \"\$PHPUNIT_XML\" ]] || PHPUNIT_XML='phpunit.xml.dist'

if [[ -f \"\$PHPUNIT_XML\" ]]; then
  eval \"\$(PHPUNIT_XML=\\\"\$PHPUNIT_XML\\\" php -r '
    \$xml = @simplexml_load_file(getenv(\"PHPUNIT_XML\") ?: \"phpunit.xml\");
    if (!\$xml || !isset(\$xml->php->env)) { exit(0); }
    foreach (\$xml->php->env as \$env) {
      \$name = (string)\$env[\"name\"];
      \$value = (string)\$env[\"value\"];
      if (!preg_match(\"/^[A-Z0-9_]+$/\", \$name)) { continue; }
      echo \"export \" . \$name . \"=\" . escapeshellarg(\$value) . PHP_EOL;
    }
  ')\"
fi

# Defaults seguros (si phpunit.xml no los trae)
export APP_ENV=\${APP_ENV:-testing}
export CACHE_DRIVER=\${CACHE_DRIVER:-array}
export SESSION_DRIVER=\${SESSION_DRIVER:-array}
export QUEUE_CONNECTION=\${QUEUE_CONNECTION:-sync}
export MAIL_MAILER=\${MAIL_MAILER:-array}

# 2) Cachea config en archivo “testing”
APP_ENV=testing APP_CONFIG_CACHE=\"\$CACHE_FILE\" php artisan config:clear --env=testing
APP_ENV=testing APP_CONFIG_CACHE=\"\$CACHE_FILE\" php artisan config:cache --env=testing

# 3) Ejecuta tests usando esa config cacheada
APP_ENV=testing APP_CONFIG_CACHE=\"\$CACHE_FILE\" php artisan test --env=testing
"
