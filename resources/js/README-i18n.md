# Sistema de Internacionalización (i18n) - Vue.js

Este proyecto incluye un sistema completo de internacionalización que permite cambiar entre español (idioma por defecto) e inglés.

## Características

- ✅ **Español como idioma por defecto**
- ✅ **Detección automática del idioma del navegador**
- ✅ **Persistencia de preferencias en localStorage**
- ✅ **Componentes reutilizables para selección de idioma**
- ✅ **Composable `useLanguage` para fácil integración**
- ✅ **Soporte completo para TypeScript**

## Estructura de archivos

```
resources/js/
├── i18n/
│   ├── index.ts          # Configuración principal de i18n
│   ├── es.json           # Traducciones en español
│   └── en.json           # Traducciones en inglés
├── composables/
│   └── useLanguage.ts    # Composable para manejar idiomas
├── components/
│   ├── LanguageSelector.vue      # Selector compacto de idioma
│   ├── LanguageSettings.vue      # Página completa de configuración
│   └── Navigation.vue            # Navegación con selector de idioma
├── types/
│   └── i18n.d.ts         # Definiciones de tipos para TypeScript
└── examples/
    └── LanguageUsage.vue # Ejemplo de uso completo
```

## Instalación

El sistema ya está configurado y listo para usar. Las dependencias necesarias están instaladas:

```bash
npm install vue-i18n@9
```

## Uso básico

### 1. En un componente Vue

```vue
<template>
  <div>
    <h1>{{ t('profile.title') }}</h1>
    <p>{{ t('profile.description') }}</p>
    <button>{{ t('common.save') }}</button>
  </div>
</template>

<script setup lang="ts">
import { useLanguage } from '@/composables/useLanguage'

const { t } = useLanguage()
</script>
```

### 2. Cambiar idioma programáticamente

```vue
<script setup lang="ts">
import { useLanguage } from '@/composables/useLanguage'

const { changeLanguage, toggleLanguage } = useLanguage()

// Cambiar a un idioma específico
const switchToEnglish = () => {
  changeLanguage('en')
}

// Alternar entre idiomas
const switchLanguage = () => {
  toggleLanguage()
}
</script>
```

### 3. Obtener información del idioma

```vue
<script setup lang="ts">
import { useLanguage } from '@/composables/useLanguage'

const {
  currentLanguage,
  getCurrentLanguageName,
  getLanguageFlag,
  isFirstVisit,
  browserLanguage
} = useLanguage()

console.log('Idioma actual:', currentLanguage.value)
console.log('Nombre del idioma:', getCurrentLanguageName.value)
console.log('Bandera:', getLanguageFlag.value)
</script>
```

## Componentes disponibles

### LanguageSelector

Selector compacto de idioma con dropdown:

```vue
<template>
  <LanguageSelector />
</template>

<script setup lang="ts">
import LanguageSelector from '@/components/LanguageSelector.vue'
</script>
```

### LanguageSettings

Página completa de configuración de idioma:

```vue
<template>
  <LanguageSettings />
</template>

<script setup lang="ts">
import LanguageSettings from '@/components/LanguageSettings.vue'
</script>
```

## Agregar nuevas traducciones

### 1. Actualizar archivos de traducción

**Español** (`resources/js/locales/es.json`):
```json
{
  "nuevo_modulo": {
    "titulo": "Título del módulo",
    "descripcion": "Descripción del módulo"
  }
}
```

**Inglés** (`resources/js/locales/en.json`):
```json
{
  "nuevo_modulo": {
    "titulo": "Module title",
    "descripcion": "Module description"
  }
}
```

### 2. Usar en componentes

```vue
<template>
  <div>
    <h1>{{ t('nuevo_modulo.titulo') }}</h1>
    <p>{{ t('nuevo_modulo.descripcion') }}</p>
  </div>
</template>
```

## Funciones del composable `useLanguage`

| Función | Descripción |
|---------|-------------|
| `t(key)` | Traducir una clave |
| `changeLanguage(locale)` | Cambiar a un idioma específico |
| `toggleLanguage()` | Alternar entre idiomas |
| `getCurrentLanguageName` | Nombre del idioma actual |
| `getLanguageFlag` | Bandera del idioma actual |
| `isFirstVisit` | Si es la primera visita |
| `browserLanguage` | Idioma del navegador |
| `isBrowserSupported` | Si el idioma del navegador es compatible |

## Configuración avanzada

### Cambiar idioma por defecto

En `resources/js/i18n/index.ts`:

```typescript
const getDefaultLocale = (): string => {
  const savedLocale = localStorage.getItem('locale')
  if (savedLocale && ['es', 'en'].includes(savedLocale)) {
    return savedLocale
  }
  return 'en' // Cambiar a inglés por defecto
}
```

### Agregar nuevos idiomas

1. Crear archivo de traducciones: `resources/js/locales/fr.json`
2. Actualizar la configuración en `resources/js/i18n/index.ts`
3. Agregar el idioma a los arrays de validación

## Eventos personalizados

El sistema emite eventos cuando cambia el idioma:

```typescript
// Escuchar cambios de idioma
window.addEventListener('locale-changed', (event) => {
  const newLocale = event.detail
  console.log('Idioma cambiado a:', newLocale)
})
```

## Persistencia

- Las preferencias de idioma se guardan en `localStorage`
- El idioma se restaura automáticamente en futuras visitas
- El atributo `lang` del HTML se actualiza automáticamente

## Compatibilidad

- ✅ Vue 3 (Composition API)
- ✅ TypeScript
- ✅ Tailwind CSS
- ✅ Inertia.js
- ✅ Laravel

## Solución de problemas

### Error: "Cannot find module 'vue-i18n'"

```bash
npm install vue-i18n@9
```

### Las traducciones no se cargan

Verificar que el archivo `resources/js/i18n/index.ts` esté importado en `app.ts`

### TypeScript no reconoce las traducciones

Verificar que el archivo `resources/js/types/i18n.d.ts` esté incluido en `tsconfig.json`

## Ejemplos completos

Ver el archivo `resources/js/examples/LanguageUsage.vue` para ejemplos completos de uso.

## Contribuir

Para agregar nuevas funcionalidades o idiomas:

1. Crear los archivos de traducción
2. Actualizar la configuración de i18n
3. Agregar tipos de TypeScript si es necesario
4. Documentar los cambios en este README
















