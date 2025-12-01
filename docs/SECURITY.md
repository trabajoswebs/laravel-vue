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

| Variable                                                         | Descripción                                    | Valor por defecto |
| ---------------------------------------------------------------- | ---------------------------------------------- | ----------------- |
| `LOGIN_MAX_ATTEMPTS` / `LOGIN_DECAY_MINUTES`                     | Intentos permitidos antes de bloquear el login | `5` / `15`        |
| `REGISTER_MAX_ATTEMPTS` / `REGISTER_DECAY_MINUTES`               | Límites para registro                          | `3` / `10`        |
| `PASSWORD_RESET_MAX_ATTEMPTS` / `PASSWORD_RESET_DECAY_MINUTES`   | Límites para recuperación de contraseña        | `3` / `10`        |
| `API_RATE_LIMIT_PER_MINUTE`                                      | Peticiones API por minuto                      | `60`              |
| `GENERAL_REQUESTS_PER_MINUTE`                                    | Límite general por IP y ruta                   | `100`             |
| `LANGUAGE_CHANGE_MAX_ATTEMPTS` / `LANGUAGE_CHANGE_DECAY_MINUTES` | Límite específico cambio de idioma             | `5` / `5`         |

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

## 7. Límites de subida

- El backend y la pipeline comparten un límite máximo de `25 MB` (`IMG_MAX_BYTES=20971520`) y un techo de `16` megapíxeles para evitar image bombs.
- Asegura que el reverse proxy aplique el mismo tope:

```nginx
client_max_body_size 25M;
client_body_timeout 30s;
```

- Replica los valores en `php.ini` para evitar desincronizaciones:

```ini
upload_max_filesize=20M
post_max_size=20M
memory_limit=512M
max_execution_time=30
```

## 8. Validación reforzada de subidas

`app/Rules/SecureImageValidation` es la entrada única para archivos de imagen (avatares, ajustes de perfil, etc.) y se ejecuta desde `UpdateAvatarRequest` antes de que el controlador invoque a `DefaultUploadService`. La regla combina los límites compartidos de `FileConstraints` con los helpers de `app/Support/Media/Security` para detectar MIMEs falsos, payloads sospechosos y normalizar el binario sin exponer los nombres originales. Cada advertencia se registra con `file_hash` y `user_id` mediante `UploadValidationLogger`, manteniendo trazabilidad sin revelar datos sensibles.

- `ImageMetadataReader` reúne `getimagesize`, `finfo` y heurísticas de animación para resolver un MIME confiable.
- `MimeNormalizer` convierte alias (`image/jpg`, `image/pjpeg`, etc.) a su forma canónica antes de validar contra la lista blanca.
- `PayloadScanner` lee los primeros `image-pipeline.scan_bytes` bytes (por defecto 50 KB) y aplica los patrones definidos en `image-pipeline.suspicious_payload_patterns`.
- `ImageNormalizer` reencodea imágenes sospechosas y sobrescribe el archivo en bloque atómico respetando `FileConstraints::maxBytes`.
- `UploadValidationLogger` anexa contexto (`event`, `file_hash`, `user_id`) a las advertencias (`image_suspicious_payload`, `image_mime_detector_mismatch`, `image_normalizer_max_bytes_exceeded`, etc.).

`DefaultUploadService` ejecuta después la fachada `ImagePipeline`, adjunta los metadatos (`version`, `mime`, `width`, `height`) y delega en `MediaReplacementService`/`MediaCleanupScheduler` para que la base de datos y los artefactos queden sincronizados. Mantén sincronizados los valores clave (`image-pipeline.allowed_mime_types`, `allowed_extensions`, `max_bytes`, `suspicious_payload_patterns`, `scan_bytes`, `normalize`) entre `.env`, `config/image-pipeline.php` y `SecureImageValidation` si cambias colecciones o formatos. Las clases de `app/Support/Media/Security` son extensibles: puedes ampliar los patrones, ajustar el logger o reutilizar `ImageMetadataReader` en otros endpoints que procesen binarios.

## 9. Migración a discos privados

- `config/filesystems.php` define los discos `avatars`, `gallery` y `quarantine` como locales privados (`storage/app/private/...`) con permisos `0600`/`0700`. No se usan automáticamente.
- Antes de apuntar `AVATAR_DISK` o `GALLERY_DISK` a estos discos, crea los directorios y ajusta ownership para el usuario del proceso (`mkdir -p storage/app/private/{avatars,gallery,quarantine}`).
- Verifica que `php artisan config:cache` siga funcionando después de cualquier cambio en `filesystems.php`; si falla, revisa comillas o permisos declarados.
- El disco `quarantine` servirá para retener subidas pendientes de análisis cuando se active ClamAV/YARA; mantén bloqueado el acceso público.

## 10. Bloqueo de ejecución en `/storage`

- A nivel de Nginx añade una regla para negar la ejecución de PHP/PHAR servidos desde `public/storage`:

```nginx
location ~* ^/storage/.*\.(php|phtml|phar)$ {
    deny all;
    return 403;
}
```

- En entornos Docker (Laravel Sail) este repositorio copia `docker/8.4/nginx/default.conf` dentro de la imagen, incluyendo la directiva anterior por defecto.
- Si tu proxy frontal es distinto (Caddy, Traefik), replica la lógica equivalente (`respond 403` para esos patrones).

- El script `scripts/check_storage_exec.sh` sube un `test.php` temporal al disco público y espera recibir `403/404`; si el servidor responde `200`, el script falla.

## 11. Endurecimiento de ImageMagick

- La política por defecto de ImageMagick debe bloquear intérpretes complejos (PDF/PS/EPS/XPS/SVG). Usa `deploy/imagemagick/policy.xml` como base y móntalo en `/etc/ImageMagick-6/policy.xml` (o `/etc/ImageMagick-7/policy.xml` según la versión).
    - Ejemplo Docker Compose: `- ./deploy/imagemagick/policy.xml:/etc/ImageMagick-6/policy.xml:ro`
    - En servidores bare-metal, copia el archivo y reinicia el servicio PHP-FPM.
- El pipeline utiliza límites defensivos (`IMG_IMAGICK_MEMORY_MB`, `IMG_IMAGICK_MAP_MB`, `IMG_IMAGICK_THREADS`) y fuerza orientación/top-left, espacio de color sRGB y `strip` para eliminar perfiles peligrosos.
- Controla el timeout de decodificación con `IMG_DECODE_TIMEOUT_SECONDS` para cortar procesamiento de bombas de descompresión extremadamente lentas.
- Para animaciones GIF, ajusta `IMG_MAX_GIF_FRAMES` (por defecto 60) y se rechazarán entradas con más fotogramas o delays nulos.

## 12. Servido firmado de conversiones

- Control de feature: `MEDIA_SIGNED_SERVE_ENABLED=false` por defecto. Actívalo en entornos donde quieras probar el endpoint firmado sin exponerlo globalmente.
- Ruta: `GET /media/avatar/{media}?c=thumb|medium|large` protegida con middleware `signed`. Usa `URL::temporarySignedRoute('media.avatar.show', now()->addMinutes(5), [...])`.
- El controlador valida colección, conversión y devuelve `response()->file()` con `X-Content-Type-Options: nosniff` y `Cache-Control` agresivo.
- Mantén este flag desactivado hasta que tengas front firmado o CDN privado; mientras tanto, úsalo solo para pruebas manuales o herramientas internas.

## 13. Referencias rápidas

| Archivo                                     | Responsabilidad                               |
| ------------------------------------------- | --------------------------------------------- |
| `app/Http/Middleware/SecurityHeaders.php`   | Genera CSP y cabeceras de seguridad           |
| `app/Http/Middleware/PreventBruteForce.php` | Rate limiting global                          |
| `app/Http/Middleware/UserAudit.php`         | Registro de auditoría anonimizando PII        |
| `config/security.php`                       | Configuración de CSP, rate limits y cabeceras |
| `docs/TRANSLATIONS_DYNAMIC.md`              | Sistema i18n (idiomas soportados, caché)      |

Mantén estos valores sincronizados entre `.env`, documentación y observabilidad para escalar de forma segura.
