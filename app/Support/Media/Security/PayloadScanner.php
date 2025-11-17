<?php

declare(strict_types=1);

namespace App\Support\Media\Security;

use RuntimeException;

/**
 * Clase encargada de escanear el contenido de archivos subidos en busca de patrones sospechosos.
 *
 * Esta clase permite configurar patrones de validación a través de la configuración y registra
 * alertas cuando detecta contenido anómalo o cuando un patrón de búsqueda es inválido.
 * Es útil para prevenir la subida de archivos que puedan contener código malicioso.
 */
final class PayloadScanner
{
    /**
     * Número máximo de bytes a leer del archivo para escanear.
     */
    private int $scanBytes;

    /**
     * Lista de patrones regex que se consideran sospechosos.
     *
     * @var non-empty-string[]
     */
    private array $patterns;

    /**
     * Constructor de la clase.
     *
     * Inicializa el escáner con los bytes máximos a leer y los patrones de búsqueda.
     * Si no se proporcionan patrones personalizados, se utilizan patrones por defecto.
     *
     * @param UploadValidationLogger $logger Instancia del logger para registrar eventos de validación.
     * @param int|null $scanBytes Bytes máximos a leer por archivo antes de escanear. Si es null, se usa la configuración predeterminada.
     * @param array<int,string>|null $patterns Lista opcional de patrones personalizados. Si es null, se usa la configuración predeterminada.
     */
    public function __construct(
        private readonly UploadValidationLogger $logger,
        ?int $scanBytes = null,
        ?array $patterns = null,
    ) {
        // Establece el número de bytes a escanear, preferiendo la configuración global.
        $defaultScanBytes = (int) config('image-pipeline.scan_bytes', 50 * 1024);
        if ($defaultScanBytes <= 0) {
            $defaultScanBytes = 50 * 1024;
        }
        $explicitScanBytes = $scanBytes ?? $defaultScanBytes;
        $this->scanBytes = max(1, $explicitScanBytes);
        // Sanitiza y establece los patrones de búsqueda
        $this->patterns = $this->sanitizePatterns($patterns ?? config('image-pipeline.suspicious_payload_patterns'));

        // Si no hay patrones válidos, establece los patrones por defecto
        if ($this->patterns === []) {
            $this->patterns = [
                '/<\?php/i', // Detecta apertura de PHP
                '/<\?=/i', // Detecta apertura corta de PHP con echo
                '/(eval|assert|system|exec|passthru|shell_exec|proc_open)\s{0,100}\(/i', // Detecta funciones de ejecución de comandos
                '/base64_decode\s{0,100}\(/i', // Detecta base64_decode que puede usarse para ofuscar código
            ];
        }
    }

    /**
     * Escanea los primeros bytes de un archivo y determina si es seguro.
     *
     * Lee una porción del archivo (hasta $this->scanBytes) y verifica si contiene
     * patrones sospechosos. Si no se puede leer el archivo, registra un error y
     * devuelve `false`.
     *
     * @param string $path Ruta absoluta al fichero que se va a escanear.
     * @param string $originalName Nombre original del archivo, para fines de registro (logging).
     * @param string|int|null $userId ID del usuario que subió el archivo, para fines de registro. Puede ser null si no aplica.
     * @return bool `true` si el archivo no contiene patrones sospechosos, `false` en caso contrario o si hubo un error al leerlo.
     */
    public function fileIsClean(string $path, string $originalName, string|int|null $userId = null): bool
    {
        // Intenta abrir el archivo en modo binario de solo lectura
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            // Si no se puede abrir, registra un warning y devuelve false
            $this->logger->warning('image_scan_failed', $originalName, ['reason' => 'unreadable'], $userId);
            return false;
        }

        try {
            // Lee la cantidad especificada de bytes del archivo
            $bytes = fread($handle, $this->scanBytes);
            // Si no se pudieron leer bytes o están vacíos, devuelve false
            if ($bytes === false || $bytes === '') {
                return false;
            }
            // Evalúa el contenido leído contra los patrones sospechosos
            return $this->contentIsClean($bytes, $originalName, $userId);
        } finally {
            // Asegura que el archivo se cierra
            fclose($handle);
        }
    }

    /**
     * Evalúa un fragmento de contenido (en memoria) frente a los patrones sospechosos.
     *
     * Toma un string de contenido binario y lo escanea en busca de patrones conocidos
     * que puedan indicar código malicioso. Registra alertas si encuentra coincidencias.
     *
     * @param string $content Contenido binario leído del archivo.
     * @param string $originalName Nombre original del archivo, para fines de registro.
     * @param string|int|null $userId ID opcional del usuario que subió el archivo, para fines de registro. Puede ser null si no aplica.
     * @return bool `true` si el contenido no contiene patrones sospechosos, `false` en caso contrario.
     * @throws RuntimeException Si alguno de los patrones regex es inválido.
     */
    public function contentIsClean(string $content, string $originalName, string|int|null $userId = null): bool
    {
        // Extrae un fragmento del contenido para escanear (hasta el límite de bytes)
        $snippet = substr($content, 0, $this->scanBytes);

        // Itera sobre cada patrón sospechoso
        foreach ($this->patterns as $pattern) {
            // Intenta hacer coincidir el patrón con el fragmento de contenido
            $match = @preg_match($pattern, $snippet);
            // Si la coincidencia falla, el patrón es inválido
            if ($match === false) {
                // Registra un warning sobre el patrón inválido
                $this->logger->warning('image_payload_pattern_invalid', $originalName, ['pattern' => $pattern], $userId);
                // Lanza una excepción indicando el patrón problemático
                throw new RuntimeException(sprintf('Invalid payload pattern: %s', $pattern));
            }
            // Si encuentra una coincidencia, el contenido es sospechoso
            if ($match === 1) {
                // Registra un warning sobre el contenido sospechoso
                $this->logger->warning('image_suspicious_payload', $originalName, ['pattern' => $pattern], $userId);
                return false; // Devuelve false indicando que el contenido no es limpio
            }
        }

        // Si no se encontró ninguna coincidencia, el contenido es limpio
        return true;
    }

    /**
     * Sanitiza y normaliza los patrones proporcionados.
     *
     * Toma una variable de entrada que puede ser un array de cadenas o un array
     * de arrays con claves 'pattern', y devuelve un array plano de cadenas no vacías.
     *
     * @param mixed $value El valor a sanitizar. Espera un array.
     * @return array<string> Un array de patrones válidos (cadenas no vacías).
     */
    private function sanitizePatterns(mixed $value): array
    {
        // Si el valor no es un array, devuelve un array vacío
        if (!is_array($value)) {
            return [];
        }

        $patterns = [];
        // Itera sobre cada elemento del array de entrada
        foreach ($value as $pattern) {
            // Si es una cadena no vacía, agrégala
            if (is_string($pattern) && $pattern !== '') {
                $patterns[] = $pattern;
                continue;
            }

            // Si es un array con una clave 'pattern' que es una cadena, úsala
            if (is_array($pattern) && isset($pattern['pattern']) && is_string($pattern['pattern'])) {
                $patterns[] = $pattern['pattern'];
            }
        }

        // Filtra cadenas vacías y reindexa el array
        return array_values(array_filter($patterns, static fn(string $p): bool => $p !== ''));
    }
}
