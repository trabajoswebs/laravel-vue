# Laravel + Vue Starter Kit

Plantilla de aplicación web con Laravel 12, Vue 3 + Inertia, TypeScript y pipeline endurecido de uploads/media.

## Stack

- Backend: Laravel 12, PHP 8.4
- Frontend: Vue 3, Inertia, Vite, TypeScript, Tailwind CSS 4
- Media: Spatie Media Library + pipeline propio en `app/Infrastructure/Uploads`
- Seguridad: validación robusta de archivos, quarantine, scanning (ClamAV/Yara), serving controlado

## Requisitos

- PHP 8.4+
- Composer
- Node.js 18+
- Base de datos (MySQL/PostgreSQL)
- Redis (recomendado para colas)
- Extensión `imagick`

## Inicio rápido

### Local

```bash
cp .env.example .env
composer install
npm install
php artisan key:generate
php artisan migrate
npm run dev
php artisan serve
```

### Con Sail

```bash
cp .env.example .env
./vendor/bin/sail up -d
./vendor/bin/sail composer install
./vendor/bin/sail npm install
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm run dev
```

## Scripts útiles

- `composer run dev`: server + queue + logs + vite
- `composer run dev:ssr`: modo SSR
- `composer run test`: limpia config cache y ejecuta tests
- `npm run lint`: ESLint con fix
- `npm run format`: Prettier sobre `resources/`
- `npm run build`: build de producción

## Arquitectura

- Árbol actualizado de `app/`: `app_tree.txt`
- Guía de capas de `app/`: `app/README.md`
- Hub de uploads/media: `app/Infrastructure/Uploads/README.md`

## Documentación

- Seguridad: `docs/SECURITY.md`
- Traducciones dinámicas: `docs/TRANSLATIONS_DYNAMIC.md`
- Lifecycle de media/cleanup: `docs/media-lifecycle.md`

## Notas operativas

- Evita ejecutar tests con `config:cache` activo; usa `composer run test`.
- El serving de media depende de la allowlist de `config/media-serving.php`.
- Los jobs de uploads/media deben tener worker activo en entornos con colas.
