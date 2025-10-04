# Banderas SVG

Este directorio contiene los archivos SVG de las banderas utilizadas en el selector de idiomas.

## Estructura

- `es.svg` - Bandera de España
- `en.svg` - Bandera del Reino Unido
- `default.svg` - Bandera por defecto (cuando no se encuentra la específica)

## Agregar nuevas banderas

Para agregar una nueva bandera:

1. Crear un archivo SVG con el código del idioma como nombre (ej: `fr.svg` para francés)
2. El SVG debe tener las siguientes características:
   - Dimensiones: 16x12 píxeles
   - ViewBox: "0 0 16 12"
   - Colores oficiales de la bandera
   - Optimizado para visualización pequeña

3. Actualizar el mapa `flagPathMap` en `LanguageSelector.vue`:

```typescript
const flagPathMap = {
    es: '/images/flags/es.svg',
    en: '/images/flags/en.svg',
    fr: '/images/flags/fr.svg', // Nueva bandera
} as Record<string, string>
```

4. Agregar las iniciales del país en `countryInitialsMap`:

```typescript
const countryInitialsMap = {
    es: 'ESP',
    en: 'GB',
    fr: 'FR', // Nuevas iniciales
} as Record<string, string>
```

## Especificaciones técnicas

- **Formato**: SVG
- **Tamaño**: 16x12 píxeles
- **Colores**: Usar colores oficiales de cada bandera
- **Optimización**: Mantener el SVG simple para mejor rendimiento
- **Accesibilidad**: Incluir atributos alt apropiados en el componente






