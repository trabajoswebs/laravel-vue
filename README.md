# Laravel Vue Starter Kit

Un kit de inicio completo para aplicaciones web modernas usando Laravel 11 y Vue 3 con Inertia.js.

## 🚀 Características Principales

- **Laravel 11** - Framework PHP moderno y robusto
- **Vue 3** - Framework JavaScript progresivo
- **Inertia.js** - Aplicaciones SPA sin la complejidad de APIs
- **TypeScript** - Tipado estático para JavaScript
- **Tailwind CSS** - Framework CSS utilitario
- **Autenticación completa** - Login, registro, verificación de email
- **Internacionalización (i18n)** - Soporte multiidioma completo
- **Traducciones dinámicas** - Sistema híbrido cliente-servidor
- **Diseño responsive** - Funciona en todos los dispositivos
- **Modo oscuro** - Soporte para temas claro/oscuro

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

## 🛠️ Instalación

### Requisitos

- PHP 8.2+
- Composer
- Node.js 18+
- MySQL/PostgreSQL

### 1. Clonar el repositorio

```bash
git clone <repository-url>
cd laravel-vue-starter-kit
```

### 2. Instalar dependencias PHP

```bash
composer install
```

### 3. Configurar entorno

```bash
cp .env.example .env
php artisan key:generate
```

### 4. Configurar base de datos

```bash
# Editar .env con tus credenciales de BD
php artisan migrate
php artisan db:seed
```

### 5. Instalar dependencias JavaScript

```bash
npm install
```

### 6. Compilar assets

```bash
npm run dev
```

### 7. Iniciar servidor

```bash
php artisan serve
```

## 📁 Estructura del Proyecto

```
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── LanguageController.php    # Controlador de idiomas
│   │   └── Middleware/
│   │       └── HandleInertiaRequests.php # Middleware de traducciones
│   └── ...
├── resources/
│   ├── js/
│   │   ├── components/                    # Componentes Vue
│   │   ├── composables/
│   │   │   └── useLanguage.ts            # Composable de idiomas
│   │   ├── i18n/                         # Configuración i18n
│   │   ├── locales/                      # Traducciones del cliente
│   │   └── pages/                        # Páginas de la aplicación
│   ├── lang/                             # Traducciones del servidor
│   │   ├── es/                           # Español
│   │   └── en/                           # Inglés
│   └── views/
│       └── app.blade.php                 # Layout principal
├── routes/
│   └── web.php                           # Rutas web incluyendo idiomas
└── docs/
    └── TRANSLATIONS_DYNAMIC.md           # Documentación del sistema
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
- [Laravel Documentation](https://laravel.com/docs) - Documentación oficial de Laravel
- [Vue.js Documentation](https://vuejs.org/guide/) - Documentación oficial de Vue
- [Inertia.js Documentation](https://inertiajs.com/) - Documentación oficial de Inertia

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
- [Vue.js Team](https://vuejs.org/) por Vue 3
- [Inertia.js Team](https://inertiajs.com/) por la integración perfecta
- [Tailwind CSS](https://tailwindcss.com/) por el sistema de diseño

## 📞 Soporte

Si tienes preguntas o necesitas ayuda:

- 📧 Email: [tu-email@ejemplo.com]
- 🐛 Issues: [GitHub Issues](https://github.com/tu-usuario/laravel-vue-starter-kit/issues)
- 💬 Discord: [Tu Servidor Discord]

---

**¡Disfruta construyendo tu próxima aplicación web! 🚀**
