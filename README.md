# Laravel Vue Starter Kit

Un kit de inicio completo para aplicaciones web modernas usando Laravel 12 y Vue 3 con Inertia.js, optimizado para desarrollo profesional.

## 🚀 Características Principales

- **Laravel 12** - Framework PHP moderno y robusto
- **Vue 3** - Framework JavaScript progresivo con Composition API
- **Inertia.js** - Aplicaciones SPA sin la complejidad de APIs
- **TypeScript** - Tipado estático para JavaScript
- **Tailwind CSS 4** - Framework CSS utilitario de última generación
- **Autenticación completa** - Login, registro, verificación de email
- **Internacionalización (i18n)** - Soporte multiidioma completo
- **Traducciones dinámicas** - Sistema híbrido cliente-servidor
- **Diseño responsive** - Funciona en todos los dispositivos
- **Modo oscuro** - Soporte para temas claro/oscuro
- **Procesamiento de imágenes endurecido** - UploadPipeline unificado (DefaultUploadService + DefaultUploadPipeline) con SecureImageValidation, normalización y OptimizerService (local/remoto)
- **Cuarentena con verificación de integridad** - LocalQuarantineRepository (hash sidecars, promote seguro) + comandos `quarantine:*` para mantener la cuarentena limpia
- **Media lifecycle resiliente** - Scheduler transaccional + CleanupMediaArtifactsJob para limpiar artefactos en cualquier disco
- **Media Library** - Gestión avanzada de archivos multimedia con Spatie
- **Docker & Laravel Sail** - Entorno de desarrollo containerizado
- **Herramientas de desarrollo** - ESLint, Prettier, TypeScript configurados y listas para CI/CD
- **Capa de seguridad documentada** - CSP, rate limiting, auditoría y cabeceras listas para producción ([ver guía](docs/SECURITY.md))

## 📁 Estructura del proyecto

### Backend, media y seguridad endurecida
- `app/Domain` agrupa value objects puros (`Media/DTO/*`, `Media/Contracts/MediaResource`) y reglas inmutables (`Security/Rules/*`) sin dependencias de Laravel.
- `app/Application/Media` orquesta lifecycle y cleanup (coordinador y handler de replacements), define puertos (`MediaProfile`, `MediaOwner`, `UploadedMedia`, uploader/scheduler/collector), VO de límites (`FileConstraints`) y DTOs de limpieza/reemplazo.
- `app/Application/User` concentra Actions/Events y los puertos/repositorios (`Contracts/*` + DTOs de resultados), el mensaje `CleanupMediaArtifacts` y el enum `ConversionReadyState` que se adaptan a jobs de infraestructura.
- `app/Application/Shared` expone puertos transversales (clock, logger, event bus, transaction manager, async job dispatcher) consumidos por adaptadores de infraestructura.
- `app/Infrastructure/Http/Controllers`, `Middleware` y `Requests` definen controladores Inertia/Auth/Settings/Media, más los middlewares (`SecurityHeaders`, `RateLimitUploads`, `SanitizeInput`, `UserAudit`) y FormRequests endurecidos con `SecureImageValidation`.
- `app/Infrastructure/Media` reúne el pipeline de imágenes (`ImagePipeline` + workflows Imagick/Fallback + `PipelineConfig`/`PipelineLogger`), perfiles (`MediaProfile`, `Profiles/*`, `ConversionProfiles/*`), adaptadores (`Adapters/SpatieMediaResource`, `HttpUploadedMedia`), jobs/listeners de conversions (`Media/Jobs`, `Media/Listeners`), optimización (`OptimizerService` + adapters) y el módulo de uploads (`Upload/*`: DefaultUploadService/Pipeline, cuarentena, ScanCoordinator, reporter/manager, excepciones) que coordinan Spatie Media Library con cuarentena verificable.
- `app/Infrastructure/Media/Security` contiene `PayloadScanner`, `ImageMetadataReader`, `ImageNormalizer`, `MimeNormalizer`, escáneres (ClamAV/Yara) y `UploadValidationLogger`, reforzando `SecureImageValidation` y el `DefaultUploadService` con normalización y auditoría anónima.
- `app/Infrastructure/Auth/Policies/Concerns/HandlesMediaOwnership.php` encapsula la verificación de propiedad y permisos elevados sobre medios para que `UserPolicy` reutilice la misma lógica entre acciones; los providers (`Infrastructure/Providers/*`) registran bindings, eventos y sanitización (HtmlPurifier/ImagePipeline/MediaLibrary).
- `app/Infrastructure/Shared/Adapters` implementa los puertos de `Application/Shared` con Laravel (colas, reloj, logger, eventos, transacciones) y `app/Infrastructure/User/Adapters` concreta los repositorios de usuario/avatar en Eloquent + Spatie.
- `app/Infrastructure/Sanitization/DisplayName` y `app/Infrastructure/Security` (helpers, firmas y excepciones de antivirus) complementan la capa de seguridad con value objects, firmas estables y manejo de fallos de escaneo.

### Frontend e internacionalización
- `resources/js/pages`, `components`, `layouts/settings` y `layouts/app` concentran las vistas Inertia, incluyendo el nuevo `AvatarUploader` y los formularios de ajustes (perfil, contraseña, apariencia).
- `resources/js/composables` y `resources/js/locales` alimentan `useLanguage`, `useAvatarUpload` y los archivos JSON que mantienen sincronizadas las traducciones cliente-servidor.
- `resources/js/lib`, `resources/js/plugins`, `resources/js/utils`, `vite.config.ts`, `tsconfig.json`, `eslint.config.js` y `package.json` definen la experiencia TypeScript/Vite con pautas de linting, paths y herramientas como `laravel-pail` para logs en tiempo real.

### Infraestructura, herramientas y documentación
- `config/` expone `security.php`, `image-pipeline.php`, `media.php`, `media-library.php`, `audit.php` y ajustes de cuarentena para gobernar políticas de CSP, rate limits, media lifecycle, hash sidecars y auditoría.
- `app/Infrastructure/Sanitization/DisplayName` convierte nombres visibles en value objects sanitizados y reutilizables, mientras que `app/Infrastructure/Security/RateLimitSignatureFactory` normaliza las firmas usadas por los limitadores de Laravel.
- `app/Infrastructure/Console/Commands` y `app/Console/Kernel.php` añaden las herramientas `quarantine:prune` y `quarantine:cleanup-sidecars` para mantener la cuarentena bajo control (programables vía scheduler).
- `deploy/`, `docker/`, `Dockerfile`, `docker-compose.yml` y `scripts/check_storage_exec.sh` contienen los artefactos de despliegue y validadores (p. ej. copia de policy.xml para ImageMagick y comprobaciones de ejecución en `/storage`).
- `docs/` aloja las guías de seguridad (`SECURITY.md`), traducciones dinámicas y media lifecycle, mientras que `app_tree.txt` y los tests (`tests/Unit`, `phpunit.xml`) mantienen la documentación viva y verificable.

## 🌍 Sistema de Internacionalización

### Traducciones Híbridas

Este proyecto implementa un sistema de traducciones híbrido que combina:

1. **Traducciones del cliente** (Vue.js) - Para la interfaz de usuario
2. **Traducciones del servidor** (Laravel) - Para mensajes del backend

### Características del Sistema i18n

✅ **Detección automática** del idioma del usuario  
✅ **Sincronización bidireccional** entre cliente y servidor  
✅ **Fallback inteligente** a traducciones del cliente  
✅ **Persistencia** en sesión, cookies y base de datos  
✅ **Cambio dinámico** sin recargar la página  
✅ **Soporte para parámetros** en traducciones

### Idiomas Soportados

- 🇪🇸 **Español** (es) - Idioma por defecto
- 🇺🇸 **English** (en) - Idioma secundario

## 🖼️ Sistema de Procesamiento de Imágenes

### ImagePipeline

Sistema avanzado de pre-procesamiento de imágenes que incluye:

✅ **Validación robusta** - Tamaño, MIME real (finfo, magic bytes)  
✅ **Normalización** - Auto-orientación, limpieza de EXIF/ICC, conversión a sRGB  
✅ **Redimensionado inteligente** - Mantiene proporciones hasta límites configurables  
✅ **Re-codificación** - Soporte para JPEG, WebP, PNG, GIF con parámetros ajustables  
✅ **GIF animados** - Conserva animaciones o toma primer frame (configurable)  
✅ **Gestión de memoria** - Cleanup automático y Value Objects seguros

### OptimizerService

Servicio de optimización de imágenes para Media Library:

✅ **Optimización completa** - Archivos originales y conversiones  
✅ **Soporte multi-disco** - Local y S3 con streaming  
✅ **Métricas detalladas** - Ahorro de espacio y estadísticas por archivo  
✅ **Límites de seguridad** - Protección contra archivos excesivamente grandes  
✅ **Whitelist de formatos** - Solo optimiza formatos compatibles  
✅ **Streaming seguro** - `RemoteDownloader` y `RemoteUploader` aseguran transferencias por stream sin agotar memoria

### Validación y protección de subidas

✅ **SecureImageValidation** - Reglas endurecidas: finfo + magic bytes, decodificación con Intervention, normalización opcional, detección de image-bombs y escaneo heurístico (`<?php`, `eval(`, `base64_decode(`, etc.)  
✅ **Rate limiting dedicado** - Middleware `rate.uploads` (registrado por `ImagePipelineServiceProvider`) limita subidas costosas según `image-pipeline.rate_limit`  
✅ **Autodiagnóstico** - `ImagePipelineServiceProvider` valida `config/image-pipeline.php` (max_bytes, bomb_ratio, rutas de escaneo, binarios permitidos) y aplica fallbacks seguros en producción  
✅ **Controles de recursos** - Límite de memoria/tokens para Imagick y GD (`resource_limits`) y escaneo seguro de archivos (`scan.*`)

### Configuración

```bash
# Instalar dependencias de imagen (requerido)
sudo apt-get install jpegoptim pngquant webp gifsicle

# Configurar parámetros en config/image-pipeline.php
# Personalizar calidades, dimensiones máximas, etc.
```

Variables de entorno clave:

- `IMG_RATE_MAX` / `IMG_RATE_DECAY` → controlan el throttling del middleware `rate.uploads`.
- `IMG_DRIVER` (con fallback automático a `IMAGE_DRIVER`) → selecciona el driver de Intervention usado por el pipeline cuando no quieres heredar el de Media Library.
- `IMG_SCAN_ALLOWED_BASE` / `IMG_SCAN_RULES_BASE` → definen rutas seguras para escaneo (yara/clamav).
- `IMG_SCAN_BIN_ALLOWLIST` / `IMG_SCAN_USE_*` → habilitan escáneres remotos (clamdscan, yara) y su lista blanca.
- `IMG_BOMB_RATIO` y `IMG_MAX_MEGAPIXELS` → protegen contra image bombs y archivos gigantes.

#### Antivirus / ClamAV

- `clamd` corre como servicio supervisado (`/usr/sbin/clamd --foreground`) y escribe en stdout/stderr; `start-container` prepara `/run/clamav` y `/var/log/clamav` con permisos de `clamav`.
- `clamdscan` es el binario principal (`IMG_SCAN_CLAMAV_BIN=/usr/bin/clamdscan`), con allowlist por defecto `"/usr/bin/clamdscan,/usr/bin/clamscan"`.
- `VIRUS_SCANNERS=clamav` por defecto; en `APP_ENV=local/testing` puedes ponerlo vacío o `none` para desactivar el escaneo.
- Timeouts recomendados para avatares: `IMG_SCAN_TIMEOUT_MS=5000`, `IMG_SCAN_CLAMAV_TIMEOUT=10`, `IMG_SCAN_IDLE_TIMEOUT=20`, `IMG_SCAN_MAX_BYTES≈4MB`, `IMG_SCAN_MAX_FILE_SIZE≈20MB`.
- El script `docker/scripts/ensure-clamav-db.sh` descarga firmas con `freshclam` en build/arranque; si necesitas forzar en desarrollo: `./vendor/bin/sail exec laravel.test ensure-clamav-db --force`.
- `wait-for-clamd` bloquea el arranque de PHP hasta que `clamdscan --stream` responde; ajusta con `CLAMD_WAIT_TIMEOUT` o desactiva temporalmente con `SKIP_CLAMD_WAIT=1` en entornos efímeros.

### Arquitectura reutilizable por colección

 Este proyecto implementa una arquitectura de subida reutilizable basada en un contrato único de perfil:

- `app/Infrastructure/Media/Upload/DefaultUploadService::upload(MediaOwner $owner, UploadedMedia $file, MediaProfile $profile, ?string $correlationId = null)`
    - Centraliza cuarentena + escaneo + normalización (`DefaultUploadPipeline`/`ImagePipeline`) y adjunta a Spatie Media Library con trazabilidad (`correlation_id`, `quarantine_id`, dimensiones, mime, headers).
- Perfiles (`app/Infrastructure/Media`):
    - `MediaProfile` (contrato): define colección, disco, conversions, flags de cuarentena/AV/normalización, TTLs y `applyConversions()`.
    - `Profiles/AvatarProfile`: usa `avatar_collection`/`avatar_disk`, TTLs configurables y delega conversions a `AvatarConversionProfile`.
    - `Profiles/GalleryProfile`: define conversions típicas de galería con tamaños configurables y TTLs propios.
- Listener multi-colecta:
    - `QueueAvatarPostProcessing` (`app/Infrastructure/Media/Listeners`) soporta múltiples colecciones configurables en `image-pipeline.postprocess_collections` (por defecto `avatar,gallery`).

### Limpieza y lifecycle de medios

- `MediaLifecycleCoordinator` coordina replace + conversions + cleanup usando DTO compartidos.
- `MediaCleanupScheduler` guarda el estado por media y programa limpieza tras conversions (local o discos remotos).
- `CleanupMediaArtifactsJob` (infra) elimina artefactos residuales (originales, conversions, responsive-images) de forma idempotente y segura a partir del mensaje `CleanupMediaArtifacts`.
- `RunPendingMediaCleanup` escucha eventos de Spatie (`ConversionHasBeenCompleted/Failed`) y dispara el scheduler oportunamente.
- Métricas centralizadas en logs (`cleanup_media_artifacts_completed`, `media_cleanup.*`) para observabilidad.
- Guía detallada en `docs/media-lifecycle.md`.

Uso rápido para otra colección (ej. galería):

1. En el modelo que almacena imágenes de galería (p. ej., `PortfolioItem`):

```php
public function registerMediaCollections(): void
{
    $this->addMediaCollection(config('image-pipeline.gallery_collection', 'gallery'))
        ->useDisk(config('image-pipeline.gallery_disk', config('filesystems.default')));
}

public function registerMediaConversions(?\Spatie\MediaLibrary\MediaCollections\Models\Media $media = null): void
{
    (new \App\Infrastructure\Media\Profiles\GalleryProfile())->applyConversions($this, $media);
}
```

2. En tu controlador/action para galería:

```php
$media = app(\App\Infrastructure\Media\Upload\DefaultUploadService::class)
    ->upload($model, $request->file('image'), new \App\Infrastructure\Media\Profiles\GalleryProfile());
```

3. Configura opcionalmente en `.env`:

```env
GALLERY_DISK=s3
GALLERY_COLLECTION=gallery
IMG_POSTPROCESS_COLLECTIONS="avatar,gallery"
```

### Avatares: subida segura (pipeline y configuración)

Este proyecto incluye un pipeline endurecido para subir el avatar del usuario (Laravel + Inertia/Vue) con validación por magic bytes, eliminación de EXIF/ICC, límites de megapíxeles y optimización del original y sus conversiones.

- Endpoints de avatar (rutas protegidas por `auth`):
    - `PATCH /settings/avatar` → actualiza el avatar (usa `UpdateAvatarRequest` + `ImagePipeline`)
    - `DELETE /settings/avatar` → elimina el avatar actual
- Concurrencia y postproceso:
    - Listener a conversions que encola `PostProcessAvatarMedia` con `WithoutOverlapping` por `mediaId` y `ShouldBeUnique`.
    - `OptimizerService` optimiza original y conversions (local y S3 por streaming).
- Validación fuerte en request y regla custom:
    - `File::image() + mimetypes + dimensions` y `SecureImageValidation` (finfo/exif, image-bomb ratio, scan heurístico).

Configuración requerida (producción):

1. Límite de tamaño a 25 MB (alineado en todas las capas)

```env
# .env
IMG_MAX_BYTES=26214400
```

```php
// config/media-library.php
'max_file_size' => (int) env('IMG_MAX_BYTES', 20 * 1024 * 1024),
```

2. Driver de imágenes

```env
# .env
IMAGE_DRIVER=imagick
```

Instala la extensión en el runtime (según distribución):

- Debian/Ubuntu: `apt-get install -y php-imagick && service php-fpm restart`
- Alpine (Docker): `apk add --no-cache php81-pecl-imagick`

3. CSP para entrega desde S3/CloudFront

```env
# .env
CSP_IMG_HOSTS=dxxxxx.cloudfront.net *.s3.amazonaws.com
```

El middleware `App\\Http\\Middleware\\SecurityHeaders` genera la CSP; `config/security.php` lee los hosts desde env.

4. Rutas de avatar (ya incluidas)

```php
// routes/settings.php
Route::patch('settings/avatar', [\\App\\Http\\Controllers\\Settings\\ProfileAvatarController::class, 'update'])
    ->name('settings.avatar.update');
Route::delete('settings/avatar', [\\App\\Http\\Controllers\\Settings\\ProfileAvatarController::class, 'destroy'])
    ->name('settings.avatar.destroy');
```

### Entrega segura de avatares firmados

La ruta pública `GET /media/avatar/{media}` (`media.avatar.show`)
sirve conversions firmadas y expira automáticamente. El controlador
`App\Infrastructure\Http\Controllers\Media\ShowAvatar` aplica:

- Middleware `signed` + `throttle:60,1` para evitar hotlinking y abusos.
- Validación estricta del parámetro `c` (`thumb`, `medium`, `large`) y del
  `Media` asociado a la colección de avatar.
- Sanitización de rutas, protección contra directory traversal y chequeo de
  firmas antes de servir el archivo.
- Generación de URLs seguras para S3 (`temporaryUrl`) o file serving local con
  cabeceras `nosniff` y `immutable`.

Para habilitar el endpoint necesitas `media.signed_serve.enabled=true`
en `config/media.php` o `config/media-signed.php` (según tu setup). Cuando está
en `false`, `ShowAvatar` responde un `NotFoundHttpException` para que Ni siquiera
se exponga la ruta. Se recomienda construir las URLs desde el backend usando:

```php
URL::signedRoute('media.avatar.show', ['media' => $media->id, 'c' => 'thumb']);
```

Documenta este flujo con tu equipo de CDN/proxy para mantener los tokens de firma
actualizados cada vez que se recargue el avatar del usuario.

Para evitar alertas de infraestructura, asegúrate de que los discos que escriben avatars
envíen las cabeceras `ACL=private` y `ContentType` (p. ej. `image/webp` o `image/png`).
Si el ACL no es privado o falta el ContentType, el helper `AvatarHeaderInspector`
lanza advertencias (`avatar.headers.acl_unexpected` / `avatar.headers.content_type_missing`)
para que el equipo detecte uploads mal configurados antes de exponerlos al público.

5. Límites del servidor para subidas (asegura que no bloqueen 20 MB)

- PHP: `upload_max_filesize=20M`, `post_max_size=20M` (php.ini)
- Nginx: `client_max_body_size 20M;`
- Workers de cola: cola `image-optimization` activa (Horizon/Supervisor)

6. Buenas prácticas de frontend

- Consumir `avatarUrl` y `avatarThumbUrl` del modelo `User` (incluyen cache busting `?v=`)
- Enviar el archivo como `FormData` en el campo `avatar`

## 🛠️ Instalación

### Opción A: Con Docker (Recomendado)

#### Requisitos

- Docker y Docker Compose
- Node.js 18+ (solo para el frontend)

#### 1. Clonar el repositorio

```bash
git clone <repository-url>
cd laravel-vue-starter-kit
```

#### 2. Configurar entorno para Sail

```bash
cp .env.example .env.sail
./vendor/bin/sail up -d
```

#### 3. Instalar dependencias

```bash
./vendor/bin/sail composer install
./vendor/bin/sail npm install
```

#### 4. Configurar aplicación

```bash
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan db:seed
```

#### 5. Compilar assets y iniciar

```bash
./vendor/bin/sail npm run dev
```

La aplicación estará disponible en `http://localhost`

### Opción B: Instalación Local

#### Requisitos

- PHP 8.2+
- Composer
- Node.js 18+
- PostgreSQL 17+ (o MySQL)
- Redis
- Extensiones PHP: Imagick (requerida por ImagePipeline), BCMath, Ctype, Fileinfo, JSON, Mbstring, OpenSSL, PDO, Tokenizer, XML

#### 1. Clonar el repositorio

```bash
git clone <repository-url>
cd laravel-vue-starter-kit
```

#### 2. Instalar dependencias PHP

```bash
composer install
```

#### 3. Configurar entorno

```bash
cp .env.example .env
php artisan key:generate
```

#### 4. Configurar base de datos

```bash
# Editar .env con tus credenciales de BD
php artisan migrate
php artisan db:seed
```

#### 5. Instalar dependencias JavaScript

```bash
npm install
```

#### 6. Compilar assets

```bash
npm run dev
```

#### 7. Iniciar servidor

```bash
php artisan serve
```

## 📁 Estructura del Proyecto

```
├── app/
│   ├── Domain/
│   │   └── Media/DTO/*                               # Value objects puros (conversiones y snapshots de reemplazo)
│   ├── Application/                                  # Orquestación y casos de uso
│   │   ├── Media/Contracts|Coordinators|DTO|Handlers # Puertos (MediaProfile/UploadedMedia/FileConstraints) + lifecycle/cleanup
│   │   └── User/Actions|Events|Jobs|Enums            # Flujo avatar (acciones, eventos, mensaje CleanupMediaArtifacts)
│   └── Infrastructure/
│       ├── Console/Kernel.php + Commands/quarantine* # Scheduler + comandos de mantenimiento
│       ├── Http/Controllers|Middleware|Requests      # Inertia/Auth/Settings + middleware de seguridad
│       ├── Http/Rules/SecureImageValidation.php      # Regla endurecida (magic bytes, bomb ratio)
│       ├── Media/                                    # ImagePipeline, perfiles, optimizador, upload/quarantine
│       │   ├── Profiles/* & ConversionProfiles/*     # Colecciones avatar/galería y límites
│       │   ├── Upload/* (DefaultUploadService, pipeline, scanner, excepciones, reporter, manager)
│       │   ├── ImagePipeline/* + OptimizerService.php    # Normalización/resize + optimización local/remota
│       │   ├── Adapters/SpatieMediaResource|HttpUploadedMedia # Adaptadores de Media Library y archivos subidos
│       │   ├── Security/* (PayloadScanner, ImageNormalizer, MimeNormalizer, scanners ClamAV/Yara, logger)
│       │   ├── DTO/Replacement*                      # Snapshots y resultados para replace seguro
│       │   ├── Models/MediaCleanupState.php (+ Concerns/TracksMediaVersions.php)
│       │   └── Services/MediaCleanupScheduler.php & Observers/MediaObserver.php
│       ├── Auth/Policies/*                           # UserPolicy + HandlesMediaOwnership
│       ├── Providers/*                               # App/Auth/Event/HtmlPurifier/ImagePipeline/MediaLibrary
│       ├── Localization/TranslationService.php       # Sincroniza i18n servidor ↔ cliente
│       ├── Models/User.php                           # Modelo con colecciones ML y accesores
│       ├── Sanitization/DisplayName.php              # Value object sanitizado para nombres visibles
│       └── Security/*                                # CSP helpers, rate limiting signatures, antivirus exceptions
├── bootstrap/app.php                                 # Registra SecurityHeaders y bootstrap Laravel
├── config/                                           # image-pipeline.php, media.php, media-library.php, security.php, audit.php, etc.
├── routes/                                           # web.php, settings.php (avatar), auth.php, console.php
├── docs/                                             # SECURITY, traducciones dinámicas, media lifecycle
├── resources/                                        # Vue 3 + Inertia + traducciones
├── tests/                                            # Feature y Unit
├── docker-compose.yml, Dockerfile, deploy/, docker/  # Infra y tooling
├── package.json, vite.config.ts, tsconfig.json, eslint.config.js
└── app_tree.txt, phpunit.xml, components.json
```

## 🌐 Uso del Sistema de Traducciones

### En Componentes Vue

```vue
<template>
    <div>
        <h1>{{ t('welcome.title') }}</h1>
        <p>{{ t('welcome.message') }}</p>
        <button>{{ t('common.save') }}</button>
    </div>
</template>

<script setup lang="ts">
import { useLanguage } from '@/composables/useLanguage';

const { t, changeLanguage, currentLanguage } = useLanguage();
</script>
```

### Cambio de Idioma

```typescript
// Cambiar a español
await changeLanguage('es');

// Cambiar a inglés
await changeLanguage('en');

// Alternar idioma
await toggleLanguage();
```

### Traducciones con Parámetros

```vue
<template>
    <p>{{ t('messages.welcome_user', user.name, appName) }}</p>
    <p>{{ t('messages.items_count', items.length) }}</p>
</template>
```

## 🛠️ Herramientas de Desarrollo

### Comandos de Desarrollo

```bash
# Desarrollo con hot reload (incluye servidor, cola, logs y Vite)
composer run dev

# Desarrollo con SSR (Server-Side Rendering)
composer run dev:ssr

# Testing
composer run test

# Formatear código JavaScript/TypeScript
npm run format

# Verificar formato sin cambios
npm run format:check

# Linter con corrección automática
npm run lint

# Build para producción
npm run build

# Build con SSR
npm run build:ssr
```

### Configuración de Entorno

```bash
# Cambiar a entorno local
composer run env:local

# Cambiar a entorno Sail
composer run env:sail
```

### Herramientas Incluidas

- **ESLint** - Linting de JavaScript/TypeScript con configuración para Vue
- **Prettier** - Formateo automático de código con plugins para Tailwind y imports
- **TypeScript** - Tipado estático con configuración optimizada
- **Vite** - Build tool moderno con HMR y optimizaciones
- **Laravel Pint** - Formateador de código PHP
- **PHPUnit** - Framework de testing para PHP
- **Laravel Pail** - Visor de logs en tiempo real

## 🔧 Configuración Avanzada

### Agregar Nuevo Idioma

1. **Crear archivos de traducción** en `resources/lang/{locale}/`
2. **Agregar al middleware** en `HandleInertiaRequests.php`
3. **Actualizar el composable** en `useLanguage.ts`
4. **Agregar metadatos** del idioma

### Agregar Nuevas Traducciones

1. **Crear archivo PHP** en `resources/lang/{locale}/`
2. **Agregar al middleware** en la lista de archivos
3. **Usar en componentes** con la función `t()`

### Variables de entorno relevantes (imágenes)

```env
# Límite de tamaño en bytes para la normalización
IMG_MAX_BYTES=20971520

# Driver de imágenes
IMAGE_DRIVER=imagick

# Colección/Disco para avatar
AVATAR_COLLECTION=avatar
AVATAR_DISK=public

# Colección/Disco para galería
GALLERY_COLLECTION=gallery
GALLERY_DISK=public

# Colecciones a postprocesar tras conversions
IMG_POSTPROCESS_COLLECTIONS="avatar,gallery"
```

## 🧪 Testing

### Probar el Sistema de Traducciones

1. **Componente de prueba** - `TranslationTester.vue`
2. **Endpoints de API** - `/api/language/*`
3. **Verificar en DevTools** - Network y Console

### Comandos de Prueba

```bash
# Cambiar idioma
curl -X POST /api/language/change/es \
  -H "X-CSRF-TOKEN: {token}" \
  -H "Content-Type: application/json"

# Obtener idioma actual
curl /api/language/current

# Obtener traducciones
curl /api/language/translations/es
```

## 📚 Documentación

- [Sistema de Traducciones Dinámicas](docs/TRANSLATIONS_DYNAMIC.md) - Guía completa del sistema i18n
- [Guía de Seguridad](docs/SECURITY.md) - Configuración de seguridad para producción
- [Media Lifecycle & Cleanup](docs/media-lifecycle.md) - Coordinación de replacements, conversions y limpieza segura
- [Laravel Documentation](https://laravel.com/docs) - Documentación oficial de Laravel
- [Vue.js Documentation](https://vuejs.org/guide/) - Documentación oficial de Vue
- [Inertia.js Documentation](https://inertiajs.com/) - Documentación oficial de Inertia
- [Tailwind CSS Documentation](https://tailwindcss.com/docs) - Documentación de Tailwind CSS 4
- [Spatie Media Library](https://spatie.be/docs/laravel-medialibrary) - Gestión de archivos multimedia

## 🤝 Contribuir

1. Fork el proyecto
2. Crea una rama para tu feature (`git checkout -b feature/AmazingFeature`)
3. Commit tus cambios (`git commit -m 'Add some AmazingFeature'`)
4. Push a la rama (`git push origin feature/AmazingFeature`)
5. Abre un Pull Request

## 📄 Licencia

Este proyecto está bajo la Licencia MIT. Ver el archivo `LICENSE` para más detalles.

## 🙏 Agradecimientos

- [Laravel Team](https://laravel.com/) por el increíble framework
- [Vue.js Team](https://vuejs.org/) por Vue 3 y su ecosistema
- [Inertia.js Team](https://inertiajs.com/) por la integración perfecta
- [Tailwind CSS](https://tailwindcss.com/) por el sistema de diseño
- [Spatie](https://spatie.be/) por las excelentes packages de Laravel
- [Vite Team](https://vitejs.dev/) por el build tool moderno
- [TypeScript Team](https://www.typescriptlang.org/) por el tipado estático

## 📞 Soporte

Si tienes preguntas o necesitas ayuda:

- 📧 Email: [tu-email@ejemplo.com]
- 🐛 Issues: [GitHub Issues](https://github.com/tu-usuario/laravel-vue-starter-kit/issues)
- 💬 Discord: [Tu Servidor Discord]

---

**¡Disfruta construyendo tu próxima aplicación web! 🚀**
