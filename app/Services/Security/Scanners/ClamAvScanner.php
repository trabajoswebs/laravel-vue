<?php

declare(strict_types=1);

namespace App\Services\Security\Scanners;

use Illuminate\Support\Facades\Log;

/**
 * Escáner concreto que utiliza ClamAV para detectar malware o virus en archivos subidos.
 *
 * Esta clase extiende `AbstractScanner` e implementa la lógica específica para interactuar
 * con el motor de antivirus ClamAV. Define argumentos permitidos, sanitiza entradas,
 * y construye el comando de ClamAV correspondiente, manejando tanto escaneos directos
 * como mediante streams para `clamdscan`.
 */
final class ClamAvScanner extends AbstractScanner
{
    /**
     * Lista blanca de argumentos soportados y su tipo esperado.
     *
     * @var array<string, array{expects: string}> Clave: nombre del argumento, Valor: tipo esperado ('flag' o 'int').
     */
    private const ALLOWED_ARGUMENTS = [
        '--no-summary'    => ['expects' => 'flag'], // Bandera sin valor
        '--fdpass'        => ['expects' => 'flag'], // Bandera específica para daemon
        '--stream'        => ['expects' => 'flag'], // Bandera para escaneo por stream
        '--disable-cache' => ['expects' => 'flag'], // Bandera para deshabilitar caché
        '--max-filesize'  => ['expects' => 'int'],  // Argumento que requiere un valor entero
        '--max-scansize'  => ['expects' => 'int'],
        '--max-recursion' => ['expects' => 'int'],
        '--timeout'       => ['expects' => 'int'],
    ];

    /**
     * Rangos seguros para argumentos enteros.
     *
     * @var array<string, array{min: int, max: int}> Clave: nombre del argumento, Valor: rango permitido.
     */
    private const INTEGER_RANGES = [
        '--timeout'       => ['min' => 1,  'max' => 30], // Segundos
        '--max-recursion' => ['min' => 1,  'max' => 32], // Nivel de recursión
    ];

    /**
     * {@inheritDoc}
     */
    protected function scannerKey(): string
    {
        return 'clamav';
    }

    /**
     * {@inheritDoc}
     *
     * Sanitiza y filtra los argumentos para el comando de ClamAV.
     * Asegura que solo se pasen argumentos permitidos y con formato correcto (banderas o enteros).
     * Limita los valores enteros a rangos seguros y al tamaño máximo del archivo si aplica.
     *
     * @param array<string, mixed>|string|null $arguments Argumentos sin procesar.
     * @param int $maxFileBytes Tamaño máximo del archivo, útil para validar argumentos.
     * @return list<string> Lista de argumentos sanitizados y seguros.
     */
    protected function sanitizeArguments(array|string|null $arguments, int $maxFileBytes): array
    {
        $tokens = [];

        if (is_array($arguments)) {
            foreach ($arguments as $argument) {
                if (is_string($argument)) {
                    $tokens[] = trim($argument);
                }
            }
        } elseif (is_string($arguments)) {
            $trimmed = trim($arguments);
            if ($trimmed !== '') {
                $tokens = preg_split('/\s+/', $trimmed) ?: [];
            }
        }

        $sanitized = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = trim((string) $tokens[$i]);
            if ($token === '') {
                continue;
            }

            if (str_contains($token, '=')) {
                [$name, $value] = explode('=', $token, 2);
                $name  = trim($name);
                $value = trim($value);
                if (! isset(self::ALLOWED_ARGUMENTS[$name])) {
                    continue;
                }

                $expects = self::ALLOWED_ARGUMENTS[$name]['expects'];
                if ($expects === 'flag') {
                    $sanitized[] = $name;
                    continue;
                }

                if ($expects === 'int' && $this->isValidInteger($value)) {
                    $sanitized[] = $name;
                    $sanitized[] = (string) $this->clampIntegerArgument($name, (int) $value, $maxFileBytes);
                }

                continue;
            }

            if (! isset(self::ALLOWED_ARGUMENTS[$token])) {
                continue;
            }

            $expects = self::ALLOWED_ARGUMENTS[$token]['expects'];
            if ($expects === 'flag') {
                $sanitized[] = $token;
                continue;
            }

            $next = $tokens[$i + 1] ?? null;
            if ($next !== null && $this->isValidInteger($next)) {
                $sanitized[] = $token;
                $sanitized[] = (string) $this->clampIntegerArgument($token, (int) $next, $maxFileBytes);
                $i++;
            }
        }

        return $sanitized;
    }

    /**
     * {@inheritDoc}
     *
     * Construye el comando específico de ClamAV.
     * Determina si usar `clamdscan` en modo stream o `clamscan` con una copia temporal del archivo.
     *
     * @param string $binary Ruta al binario de ClamAV.
     * @param array<string, string> $arguments Argumentos sanitizados.
     * @param array<string, mixed> $target Información sobre el archivo objetivo (path, handle, etc.).
     * @param array<string, mixed> $scanConfig Configuración global del escaneo.
     * @param array<string, mixed> $scannerConfig Configuración específica del escáner.
     * @return array{command: list<string>, input: resource|null, cleanup: (callable|null), uses_target_handle?: bool, target_closed?: bool}|null
     *         Array con el comando, handle de entrada opcional, función de limpieza y flags de estado,
     *         o `null` si la construcción falla.
     */
    protected function buildCommand(
        string $binary,
        array $arguments,
        array $target,
        array $scanConfig,
        array $scannerConfig,
    ): ?array {
        $maxFileBytes = $this->resolveMaxFileBytes($scanConfig, $scannerConfig);
        [$finalArguments, $isDaemon] = $this->ensureStreamArguments($arguments, $binary, $maxFileBytes);

        if ($isDaemon) {
            // Escaneo por stream (clamdscan).
            return [
                'command' => array_merge([$binary], $finalArguments, ['-']), // '-' indica lectura desde stdin
                'input' => $target['handle'],
                'uses_target_handle' => true, // El proceso usará el handle directamente
            ];
        }

        // Escaneo directo de archivo (clamscan).
        $copy = $this->createTemporaryCopy($target['handle'], $target['display_name']);
        if ($copy === null) {
            return null;
        }

        return [
            'command' => array_merge([$binary], $finalArguments, [$copy['path']]),
            'input' => null,
            'cleanup' => $copy['cleanup'],
            'target_closed' => true, // El handle del target ya ha sido cerrado por createTemporaryCopy
        ];
    }

    /**
     * Ajusta los argumentos para garantizar la compatibilidad con el binario de ClamAV.
     *
     * Si se detecta `clamdscan`, se asegura de que se use `--stream` y se ignora `--fdpass`.
     * También aplica límites de tamaño si `maxFileBytes` es positivo.
     *
     * @param array<int, string> $arguments Lista de argumentos originales.
     * @param string $binary Ruta al binario de ClamAV.
     * @param int $maxFileBytes Tamaño máximo del archivo.
     * @return array{0: list<string>, 1: bool} Tupla con la lista de argumentos ajustados y un booleano
     *         que indica si se debe usar escaneo por stream.
     */
    private function ensureStreamArguments(array $arguments, string $binary, int $maxFileBytes): array
    {
        $isClamDaemon = $this->isClamDaemon($binary);
        $filtered = [];
        $hasStream = false;
        $hasMaxFilesize = false;
        $hasMaxScansize = false;

        foreach ($arguments as $argument) {
            if ($argument === '--fdpass' && $isClamDaemon) {
                // `--fdpass` no es compatible con `clamdscan` en modo stream
                continue;
            }

            if ($argument === '--stream') {
                $hasStream = true;
            }

            if ($argument === '--max-filesize') {
                $hasMaxFilesize = true;
            }

            if ($argument === '--max-scansize') {
                $hasMaxScansize = true;
            }

            $filtered[] = $argument;
        }

        if ($isClamDaemon && ! $hasStream) {
            // `clamdscan` requiere `--stream` para escanear por stdin
            $filtered[] = '--stream';
        }

        if ($maxFileBytes > 0) {
            // Aplica límites de tamaño si no están ya presentes
            if (! $hasMaxFilesize) {
                $filtered[] = '--max-filesize';
                $filtered[] = (string) $maxFileBytes;
            }

            if (! $hasMaxScansize) {
                $filtered[] = '--max-scansize';
                $filtered[] = (string) $maxFileBytes;
            }
        }

        return [$filtered, $isClamDaemon];
    }

    /**
     * Limita un valor entero de un argumento a rangos seguros y al tamaño máximo del archivo.
     *
     * @param string $argument Nombre del argumento (por ejemplo, '--timeout').
     * @param int $value Valor original.
     * @param int $maxFileBytes Tamaño máximo del archivo.
     * @return int Valor ajustado.
     */
    private function clampIntegerArgument(string $argument, int $value, int $maxFileBytes): int
    {
        if (isset(self::INTEGER_RANGES[$argument])) {
            $min = self::INTEGER_RANGES[$argument]['min'];
            $max = self::INTEGER_RANGES[$argument]['max'];
            $value = max($min, min($max, $value));
        }

        // Para argumentos de tamaño de archivo, limita al tamaño máximo permitido globalmente
        if (in_array($argument, ['--max-filesize', '--max-scansize'], true) && $maxFileBytes > 0) {
            $value = min($value, $maxFileBytes); // Ej.: max_file_size_bytes=2MB → clamp
        }

        return $value;
    }

    /**
     * Verifica si un valor es un entero positivo representado como string o int.
     *
     * @param mixed $value Valor a verificar.
     * @return bool `true` si es un entero positivo, `false` en caso contrario.
     */
    private function isValidInteger(mixed $value): bool
    {
        if (! is_string($value) && ! is_int($value)) {
            return false;
        }

        $stringValue = (string) $value;
        return $stringValue !== '' && ctype_digit($stringValue);
    }

    /**
     * Determina si el binario es `clamdscan` o similar.
     *
     * @param string $binary Ruta al binario.
     * @return bool `true` si parece ser un binario de daemon de ClamAV.
     */
    private function isClamDaemon(string $binary): bool
    {
        $basename = strtolower(basename($binary));
        return str_contains($basename, 'clamd');
    }

    /**
     * Crea una copia temporal segura del archivo para escanearlo directamente.
     *
     * Este método se usa cuando no se puede escanear por stream (por ejemplo, con `clamscan`).
     * Cierra el handle original del archivo.
     *
     * @param resource $handle Handle del archivo original.
     * @param string $displayName Nombre del archivo para logs.
     * @return array{path: string, cleanup: callable}|null Ruta de la copia temporal y función de limpieza,
     *         o `null` si falla.
     */
    private function createTemporaryCopy($handle, string $displayName): ?array
    {
        if (! is_resource($handle)) {
            return null;
        }

        $temp = tempnam(sys_get_temp_dir(), 'clam_scan_');
        if ($temp === false) {
            Log::error('image_scan.clamav_temp_create_failed', ['file' => $displayName]);
            return null;
        }

        if (! unlink($temp)) {
            Log::error('image_scan.clamav_temp_unlink_failed', ['file' => $displayName, 'path' => $temp]);
            return null;
        }

        $out = fopen($temp, 'xb'); // 'x' crea el archivo exclusivamente, 'b' lo abre en modo binario.
        if ($out === false) {
            Log::error('image_scan.clamav_temp_open_failed', ['file' => $displayName]);
            return null;
        }

        rewind($handle);
        if (stream_copy_to_stream($handle, $out) === false) {
            fclose($out);
            unlink($temp);
            Log::error('image_scan.clamav_temp_write_failed', ['file' => $displayName]);
            return null;
        }

        if (fclose($out) === false) {
            unlink($temp);
            Log::error('image_scan.clamav_temp_close_failed', ['file' => $displayName]);
            return null;
        }

        if (! chmod($temp, 0600)) { // Solo lectura/escritura para el propietario.
            unlink($temp);
            Log::error('image_scan.clamav_temp_chmod_failed', ['file' => $displayName]);
            return null;
        }

        // Cierra el handle original después de la copia
        fclose($handle);

        return [
            'path' => $temp,
            'cleanup' => static function () use ($temp): void {
                if (file_exists($temp)) {
                    unlink($temp);
                }
            },
        ];
    }
}