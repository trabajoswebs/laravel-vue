# i18n en `resources/js`

Guía rápida del sistema de internacionalización de frontend.

## Estado actual

- Idiomas soportados: `es` y `en`.
- Locale por defecto: `es`.
- Archivos de mensajes: `resources/js/locales/es.json`, `resources/js/locales/en.json`.
- Configuración i18n: `resources/js/i18n/index.ts`.
- Composable principal: `resources/js/composables/useLanguage.ts`.

## Componentes relacionados

- `resources/js/components/LanguageSelector.vue`
- `resources/js/components/LanguageSettings.vue`
- `resources/js/components/Navigation.vue`

## Uso básico

```ts
import { useLanguage } from '@/composables/useLanguage'

const { t, currentLanguage, changeLanguage, toggleLanguage } = useLanguage()
```

## Añadir una traducción

1. Agrega la clave en `resources/js/locales/es.json`.
2. Agrega la misma clave en `resources/js/locales/en.json`.
3. Usa `t('ruta.clave')` en el componente Vue.

## Añadir un idioma nuevo

1. Crea `resources/js/locales/<locale>.json`.
2. Registra el locale en `resources/js/i18n/index.ts`.
3. Ajusta metadata y soporte en `resources/js/composables/useLanguage.ts`.
4. Añade bandera en `public/images/flags/` y mapeo del selector.

## Verificación rápida

- Ejecuta `npm run lint`.
- Arranca `npm run dev` y valida cambio de idioma en UI.
- Confirma que no hay claves sin traducir en consola/logs.
