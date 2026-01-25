<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Security;

/**
 * Clase encargada de normalizar tipos MIME y aplicar alias comunes.
 *
 * Esta clase proporciona un método estático para convertir tipos MIME
 * a su forma canónica, eliminando parámetros innecesarios y aplicando
 * mapeos de alias comunes para garantizar consistencia en la representación
 * de los tipos MIME.
 */
final class MimeNormalizer
{
    /**
     * Mapa de alias de MIME a sus equivalentes canónicos.
     *
     * Este array define equivalencias comunes de tipos MIME que pueden
     * aparecer en diferentes contextos pero que representan el mismo formato.
     * Por ejemplo, 'image/jpg' y 'image/pjpeg' se mapean a 'image/jpeg'.
     */
    private const MIME_ALIAS_MAP = [
        'image/jpg' => 'image/jpeg',      // Alias común para JPEG
        'image/pjpeg' => 'image/jpeg',    // JPEG progresivo
        'image/x-png' => 'image/png',     // Variante de PNG
        'image/x-webp' => 'image/webp',   // Variante de WebP
        'image/x-avif' => 'image/avif',   // Variante de AVIF
    ];

    /**
     * Constructor privado para evitar la instanciación de la clase.
     *
     * Esta clase está diseñada para ser utilizada únicamente a través
     * de sus métodos estáticos, por lo que su constructor es privado.
     */
    private function __construct()
    {
    }

    /**
     * Convierte un MIME a su forma canónica sin parámetros.
     *
     * Este método toma un string de tipo MIME (por ejemplo, 'image/jpeg; charset=utf-8')
     * y lo procesa para devolver su forma canónica:
     * - Lo convierte a minúsculas
     * - Elimina espacios en blanco
     * - Remueve cualquier parámetro adicional después del punto y coma (;)
     * - Aplica el mapeo de alias si es necesario
     *
     * @param string|null $mime El tipo MIME a normalizar. Puede ser null.
     * @return string|null El MIME normalizado y canónico, o null si la entrada no es válida o está vacía.
     */
    public static function normalize(?string $mime): ?string
    {
        // Si la entrada no es un string, devuelve null
        if (!is_string($mime)) {
            return null;
        }

        // Convierte a minúsculas y elimina espacios en blanco al inicio y final
        $normalized = strtolower(trim($mime));
        // Si después de la limpieza está vacío, devuelve null
        if ($normalized === '') {
            return null;
        }

        // Busca la posición del primer punto y coma (inicio de parámetros)
        $semicolon = strpos($normalized, ';');
        if ($semicolon !== false) {
            // Recorta el string para mantener solo la parte del tipo MIME base
            $normalized = substr($normalized, 0, $semicolon);
        }

        // Verifica si el MIME normalizado tiene un alias definido
        if (isset(self::MIME_ALIAS_MAP[$normalized])) {
            // Reemplaza el alias por su forma canónica
            $normalized = self::MIME_ALIAS_MAP[$normalized];
        }

        // Devuelve el MIME completamente normalizado
        return $normalized;
    }
}