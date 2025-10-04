# Seguridad y Configuración Operativa

Este proyecto incluye varias capas de endurecimiento. A continuación se describe cómo configurarlas y mantenerlas.

## 1. Content Security Policy (CSP)

La política se define en `config/security.php` y se alimenta mediante variables de entorno:

- `CSP_IMG_HOSTS`: dominios permitidos para cargar imágenes en producción (por ejemplo, CDN o bucket de S3).
- `CSP_CONNECT_HOSTS`: endpoints adicionales permitidos en `connect-src` (APIs, websockets, etc.).
- `CSP_REPORT_URI` / `CSP_REPORT_TO`: endpoints opcionales para recibir reportes de violaciones CSP.
- `CSP_DEV_IMG_HOSTS` / `CSP_DEV_CONNECT_HOSTS`: equivalentes para el entorno de desarrollo.

> **Nota:** El middleware `SecurityHeaders` propaga el nonce a Vite. Verifica que cualquier script/style inline use el atributo `nonce` o se elimine.

## 2. Rate Limiting

Todos los límites se centralizan en `config/security.php` bajo la llave `rate_limiting`. Las variables de entorno relevantes son:

| Variable | Descripción | Valor por defecto |
|----------|-------------|-------------------|
| `LOGIN_MAX_ATTEMPTS` / `LOGIN_DECAY_MINUTES` | Intentos permitidos antes de bloquear el login | `5` / `15` |
| `REGISTER_MAX_ATTEMPTS` / `REGISTER_DECAY_MINUTES` | Límites para registro | `3` / `10` |
| `PASSWORD_RESET_MAX_ATTEMPTS` / `PASSWORD_RESET_DECAY_MINUTES` | Límites para recuperación de contraseña | `3` / `10` |
| `API_RATE_LIMIT_PER_MINUTE` | Peticiones API por minuto | `60` |
| `GENERAL_REQUESTS_PER_MINUTE` | Límite general por IP y ruta | `100` |
| `LANGUAGE_CHANGE_MAX_ATTEMPTS` / `LANGUAGE_CHANGE_DECAY_MINUTES` | Límite específico cambio de idioma | `5` / `5` |

El middleware `PreventBruteForce` usa estas configuraciones y evita bloquear métodos `GET/HEAD` innecesariamente.

## 3. Auditoría y Logs

- Ajusta `AUDIT_SAMPLE_RATE`, `AUDIT_RETENTION_DAYS`, `AUDIT_LOG_CHANNEL` y `AUDIT_SECURITY_CHANNEL` según la carga esperada.
- El middleware `UserAudit` enmascara IPs (`SecurityHelper::hashIp`) y dominios de email para evitar exponer PII. Si necesitas anonimizar otro dato, amplía `SecurityHelper::sanitizeForLogging`.
- Usa herramientas de rotación/almacenamiento (CloudWatch, ELK) para cumplir con tu política de retención.

## 4. Sesiones

Asegura que en producción los siguientes flags se encuentren en `.env`:

```
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=strict
SESSION_HTTP_ONLY=true
```

Si la app corre detrás de un proxy HTTPS considera definir `SESSION_DOMAIN` y `TRUSTED_PROXIES`.

## 5. Caching y Optimización

El pipeline (`.github/workflows/tests.yml`) ejecuta `config:cache`, `event:cache` y `route:cache`. En producción ejecuta también:

```
php artisan optimize
php artisan view:cache
php artisan queue:restart
```

Para tareas costosas (notificaciones, auditoría pesada) configura `QUEUE_CONNECTION` y despliega workers (p.ej. Horizon) en el entorno productivo.

## 6. CDN / Caché HTTP

- Sirve los assets estáticos mediante CDN (S3 + CloudFront) y habilita `Cache-Control`/`ETag` a nivel CDN.
- Define en tu CDN políticas de expiración adecuadas (p.ej. 1 año para archivos versionados).

## 7. Referencias rápidas

| Archivo | Responsabilidad |
|---------|-----------------|
| `app/Http/Middleware/SecurityHeaders.php` | Genera CSP y cabeceras de seguridad |
| `app/Http/Middleware/PreventBruteForce.php` | Rate limiting global |
| `app/Http/Middleware/UserAudit.php` | Registro de auditoría anonimizando PII |
| `config/security.php` | Configuración de CSP, rate limits y cabeceras |
| `docs/TRANSLATIONS_DYNAMIC.md` | Sistema i18n (idiomas soportados, caché) |

Mantén estos valores sincronizados entre `.env`, documentación y observabilidad para escalar de forma segura.
