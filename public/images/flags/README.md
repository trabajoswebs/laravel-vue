# Banderas SVG

Este directorio contiene banderas usadas por el selector de idioma.

## Archivos actuales

- `es.svg`: español
- `en.svg`: inglés
- `default.svg`: fallback

## Reglas para agregar una bandera

1. Nombre de archivo por locale (`fr.svg`, `pt.svg`, etc.).
2. Mantener proporción 16x12 (viewBox `0 0 16 12`).
3. Optimizar SVG para tamaño pequeño en UI.
4. Registrar el nuevo locale en `resources/js/components/LanguageSelector.vue`.

## Verificación

- Iniciar `npm run dev`.
- Confirmar que el selector muestra la nueva bandera sin romper layout.
