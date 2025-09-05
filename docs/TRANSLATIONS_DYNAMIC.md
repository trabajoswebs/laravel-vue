# Sistema de Traducciones Dinámicas del Servidor

## 🎯 Descripción General

Este sistema permite que el backend de Laravel envíe traducciones específicas al frontend de Vue.js, permitiendo una gestión centralizada y dinámica de los idiomas. Las traducciones se cargan automáticamente desde el servidor y se sincronizan con el cliente.

## 🏗️ Arquitectura

### Backend (Laravel)
- **Middleware**: `HandleInertiaRequests` - Detecta el idioma del usuario y carga las traducciones
- **Controlador**: `LanguageController` - Maneja el cambio de idioma y devuelve traducciones
- **Archivos de traducción**: Ubicados en `resources/lang/{locale}/`

### Frontend (Vue.js)
- **Composable**: `useLanguage` - Gestiona las traducciones del servidor y cliente
- **Función híbrida**: `t()` - Primero busca en el servidor, luego fallback al cliente

## 🚀 Características

✅ **Detección automática de idioma** basada en múltiples fuentes  
✅ **Sincronización bidireccional** entre servidor y cliente  
✅ **Fallback inteligente** a traducciones del cliente  
✅ **Persistencia** en sesión, cookies y base de datos  
✅ **Cambio dinámico** sin recargar la página  
✅ **Soporte para parámetros** en traducciones  

## 📁 Estructura de Archivos

```
app/
├── Http/
│   ├── Middleware/
│   │   └── HandleInertiaRequests.php    # Middleware principal
│   └── Controllers/
│       └── LanguageController.php       # Controlador de idiomas
├── resources/
│   ├── lang/
│   │   ├── es/                          # Traducciones en español
│   │   │   ├── validation.php
│   │   │   ├── auth.php
│   │   │   ├── messages.php
│   │   │   └── ...
│   │   ├── en/                          # Traducciones en inglés
│   │   └── es.json                      # Traducciones JSON
│   └── js/
│       ├── composables/
│       │   └── useLanguage.ts           # Composable principal
│       └── locales/
│           ├── es.json                   # Traducciones del cliente
│           └── en.json
routes/
└── web.php                              # Rutas de idioma
```

## 🔧 Configuración

### 1. Middleware de Inertia

El middleware `HandleInertiaRequests` se ejecuta en cada petición y:

1. **Detecta el idioma** del usuario
2. **Carga las traducciones** correspondientes
3. **Envía los datos** a través de `serverTranslations`

```php
'serverTranslations' => [
    'locale' => $locale,
    'messages' => $translations,
    'fallbackLocale' => config('app.fallback_locale', 'en'),
],
```

### 2. Controlador de Idioma

Maneja tres endpoints principales:

- `GET /api/language/current` - Obtiene el idioma actual
- `GET /api/language/translations/{locale}` - Obtiene traducciones de un idioma
- `POST /api/language/change/{locale}` - Cambia el idioma del usuario

### 3. Composable useLanguage

Proporciona una función `t()` híbrida que:

1. **Primero busca** en las traducciones del servidor
2. **Si no encuentra**, usa las traducciones del cliente
3. **Aplica parámetros** si existen

## 📖 Uso

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

### Acceso a Traducciones del Servidor

```typescript
const { 
  getServerTranslation, 
  hasServerTranslation,
  serverTranslations 
} = useLanguage()

// Verificar si existe una traducción del servidor
if (hasServerTranslation('validation.required')) {
  const message = getServerTranslation('validation.required')
}

// Acceder directamente a las traducciones
console.log(serverTranslations.value.messages.validation)
```

## 🔍 Detección de Idioma

El sistema detecta el idioma en este orden de prioridad:

1. **Usuario autenticado** - Campo `locale`, `language`, o `preferred_language`
2. **Sesión** - Variable `locale` en la sesión
3. **Cookie** - Cookie `locale` (dura 1 año)
4. **Navegador** - Header `Accept-Language`
5. **Por defecto** - Configuración de la aplicación

## 📝 Agregar Nuevas Traducciones

### 1. Crear archivo PHP

```php
// resources/lang/es/nuevo.php
<?php

return [
    'titulo' => 'Mi Título',
    'mensaje' => 'Mi Mensaje',
    'acciones' => [
        'guardar' => 'Guardar',
        'cancelar' => 'Cancelar'
    ]
];
```

### 2. Agregar al middleware

```php
// En HandleInertiaRequests.php
$translationFiles = [
    'validation',
    'auth',
    'nuevo',  // ← Agregar aquí
    // ...
];
```

### 3. Usar en Vue

```vue
<template>
  <h1>{{ t('nuevo.titulo') }}</h1>
  <p>{{ t('nuevo.mensaje') }}</p>
  <button>{{ t('nuevo.acciones.guardar') }}</button>
</template>
```

## 🎨 Traducciones con Parámetros

### En el servidor

```php
// resources/lang/es/messages.php
'welcome_user' => '¡Hola :name! Bienvenido a :app',
'items_count' => 'Tienes :count elementos',
```

### En Vue

```vue
<template>
  <p>{{ t('messages.welcome_user', user.name, appName) }}</p>
  <p>{{ t('messages.items_count', items.length) }}</p>
</template>
```

## 🚨 Manejo de Errores

### Fallback automático

Si hay un error cargando traducciones del servidor:

1. Se registra el error en los logs
2. Se cargan las traducciones del idioma por defecto
3. Se mantiene la funcionalidad de la aplicación

### Logs

```php
Log::warning("Error cargando traducciones para {$locale}: " . $e->getMessage());
Log::error('Error cambiando idioma: ' . $e->getMessage());
```

## 🔄 Flujo de Cambio de Idioma

1. **Usuario hace clic** en cambiar idioma
2. **Frontend llama** a `/api/language/change/{locale}`
3. **Servidor actualiza**:
   - Sesión del usuario
   - Cookie del navegador
   - Base de datos (si está autenticado)
4. **Servidor devuelve** nuevas traducciones
5. **Frontend actualiza** estado y recarga la página
6. **Nuevas traducciones** se aplican automáticamente

## 🧪 Testing

### Probar cambio de idioma

```bash
# Cambiar a español
curl -X POST /api/language/change/es \
  -H "X-CSRF-TOKEN: {token}" \
  -H "Content-Type: application/json"

# Obtener idioma actual
curl /api/language/current

# Obtener traducciones
curl /api/language/translations/es
```

### Verificar en el navegador

1. Abrir DevTools → Network
2. Cambiar idioma en la aplicación
3. Verificar la petición POST a `/api/language/change/{locale}`
4. Verificar que se reciben las nuevas traducciones

## 🎯 Beneficios

✅ **Centralización** - Todas las traducciones en un lugar  
✅ **Consistencia** - Mismo idioma en frontend y backend  
✅ **Flexibilidad** - Cambio dinámico sin recargar  
✅ **Performance** - Traducciones cargadas solo cuando se necesitan  
✅ **Mantenibilidad** - Fácil agregar nuevos idiomas y traducciones  
✅ **UX mejorada** - Cambio instantáneo de idioma  

## 🔮 Futuras Mejoras

- [ ] **Caché de traducciones** para mejor performance
- [ ] **Lazy loading** de archivos de traducción
- [ ] **Soporte para más idiomas** (fr, de, it, etc.)
- [ ] **Traducciones condicionales** basadas en contexto
- [ ] **Sincronización en tiempo real** entre usuarios
- [ ] **Backup automático** de traducciones
- [ ] **Interfaz de administración** para traducciones

