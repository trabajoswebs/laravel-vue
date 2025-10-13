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
- **Procesamiento de imágenes avanzado** - Pipeline de optimización con ImagePipeline y OptimizerService
- **Media Library** - Gestión avanzada de archivos multimedia con Spatie
- **Docker & Laravel Sail** - Entorno de desarrollo containerizado
- **Herramientas de desarrollo** - ESLint, Prettier, TypeScript configurados
- **Capa de seguridad documentada** - CSP, rate limiting, auditoría y cabeceras listas para producción ([ver guía](docs/SECURITY.md))

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

### Configuración

```bash
# Instalar dependencias de imagen (requerido)
sudo apt-get install jpegoptim pngquant webp gifsicle

# Configurar parámetros en config/image-pipeline.php
# Personalizar calidades, dimensiones máximas, etc.
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

1) Límite de tamaño a 10 MB (alineado en todas las capas)

```env
# .env
IMG_MAX_BYTES=10485760
```

```php
// config/media-library.php
'max_file_size' => (int) env('IMG_MAX_BYTES', 10 * 1024 * 1024),
```

2) Driver de imágenes

```env
# .env
IMAGE_DRIVER=imagick
```

Instala la extensión en el runtime (según distribución):
- Debian/Ubuntu: `apt-get install -y php-imagick && service php-fpm restart`
- Alpine (Docker): `apk add --no-cache php81-pecl-imagick`

3) CSP para entrega desde S3/CloudFront

```env
# .env
CSP_IMG_HOSTS=dxxxxx.cloudfront.net *.s3.amazonaws.com
```

El middleware `App\\Http\\Middleware\\SecurityHeaders` genera la CSP; `config/security.php` lee los hosts desde env.

4) Rutas de avatar (ya incluidas)

```php
// routes/settings.php
Route::patch('settings/avatar', [\\App\\Http\\Controllers\\Settings\\ProfileAvatarController::class, 'update'])
    ->name('settings.avatar.update');
Route::delete('settings/avatar', [\\App\\Http\\Controllers\\Settings\\ProfileAvatarController::class, 'destroy'])
    ->name('settings.avatar.destroy');
```

5) Límites del servidor para subidas (asegura que no bloqueen 10 MB)

- PHP: `upload_max_filesize=10M`, `post_max_size=10M` (php.ini)
- Nginx: `client_max_body_size 10M;`
- Workers de cola: cola `image-optimization` activa (Horizon/Supervisor)

6) Buenas prácticas de frontend

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
- Extensiones PHP: Imagick, BCMath, Ctype, Fileinfo, JSON, Mbstring, OpenSSL, PDO, Tokenizer, XML

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
│   ├── Actions/                          # Actions de Laravel
│   │   └── Profile/                      # Actions relacionadas con perfil
│   │       └── UpdateAvatar.php         # Actualización de avatar
│   ├── Events/                          # Eventos de la aplicación
│   │   └── User/                        # Eventos de usuario
│   │       ├── AvatarDeleted.php        # Evento de avatar eliminado
│   │       └── AvatarUpdated.php        # Evento de avatar actualizado
│   ├── Http/
│   │   ├── Controllers/                 # Controladores
│   │   │   └── LanguageController.php   # Controlador de idiomas
│   │   └── Middleware/
│   │       └── HandleInertiaRequests.php # Middleware de traducciones
│   ├── Models/                          # Modelos de Eloquent
│   │   └── User.php                     # Modelo de usuario
│   └── Services/                        # Servicios de la aplicación
│       ├── ImagePipeline.php            # Pipeline de procesamiento de imágenes
│       └── OptimizerService.php         # Servicio de optimización de imágenes
├── config/
│   └── filesystems.php                  # Configuración de sistemas de archivos
├── docs/
│   ├── SECURITY.md                      # Guía de seguridad
│   └── TRANSLATIONS_DYNAMIC.md          # Documentación del sistema i18n
├── resources/
│   ├── js/
│   │   ├── components/                  # Componentes Vue
│   │   ├── composables/
│   │   │   └── useLanguage.ts          # Composable de idiomas
│   │   ├── i18n/                       # Configuración i18n
│   │   ├── locales/                    # Traducciones del cliente
│   │   └── pages/                      # Páginas de la aplicación
│   ├── lang/                           # Traducciones del servidor
│   │   ├── es/                         # Español
│   │   └── en/                         # Inglés
│   └── views/
│       └── app.blade.php               # Layout principal
├── routes/
│   └── web.php                         # Rutas web incluyendo idiomas
├── docker-compose.yml                  # Configuración de Docker
├── composer.json                       # Dependencias PHP
├── package.json                        # Dependencias JavaScript
├── tsconfig.json                       # Configuración de TypeScript
├── vite.config.ts                      # Configuración de Vite
├── eslint.config.js                    # Configuración de ESLint
└── components.json                     # Configuración de componentes UI
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
import { useLanguage } from '@/composables/useLanguage'

const { t, changeLanguage, currentLanguage } = useLanguage()
</script>
```

### Cambio de Idioma

```typescript
// Cambiar a español
await changeLanguage('es')

// Cambiar a inglés
await changeLanguage('en')

// Alternar idioma
await toggleLanguage()
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
