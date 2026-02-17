<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Pipeline\Scanning\Scanners;

use App\Support\Security\Exceptions\AntivirusException;
use App\Modules\Uploads\Pipeline\Security\Logging\MediaSecurityLogger;

/**
 * Escáner concreto que utiliza ClamAV para detectar malware en archivos subidos.
 *
 * Esta clase implementa la integración específica con ClamAV:
 * - Soporte para clamdscan (streaming) y clamscan (copia temporal).
 * - Sanitización y clamping de argumentos mediante lista blanca.
 * - Resolución de binario con fallbacks y lista blanca.
 * - Sin dependencias ocultas; toda la configuración se inyecta.
 *
 * @package App\Modules\Uploads\Pipeline\Scanning\Scanners
 */
final class ClamAvScanner extends AbstractScanner
{
    /**
     * Lista blanca de argumentos soportados con su tipo y rango seguro.
     *
     * @var array<string, array{expects: string, min?: int, max?: int}>
     */
    private const ALLOWED_ARGUMENTS = [
        '--no-summary'    => ['expects' => 'flag'],
        '--fdpass'        => ['expects' => 'flag'],
        '--stream'        => ['expects' => 'flag'],
        '--disable-cache' => ['expects' => 'flag'],
        '--max-filesize'  => ['expects' => 'int', 'min' => 1, 'max' => PHP_INT_MAX],
        '--max-scansize'  => ['expects' => 'int', 'min' => 1, 'max' => PHP_INT_MAX],
        '--max-recursion' => ['expects' => 'int', 'min' => 1, 'max' => 32],
        '--timeout'       => ['expects' => 'int', 'min' => 1, 'max' => 30],
    ];

    /**
     * Cache para determinar si el binario es clamdscan.
     */
    private ?bool $isDaemonCache = null;

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
     * Resuelve el binario de ClamAV utilizando la configuración inyectada.
     * NO utiliza helpers globales (config, app).
     */
    protected function resolveExecutable(array $scannerConfig): ?string
    {
        static $loggedMissing = false;

        $allowlist = $this->allowedBinaries();
        if ($allowlist === []) {
            if (! $loggedMissing) {
                $this->logScan('error', 'image_scan.clamav_binary_not_allowlisted', [
                    'reason' => 'empty_allowlist',
                ]);
                $loggedMissing = true;
            }
            return null;
        }

        $failures = [];

        foreach ($this->binaryCandidates($scannerConfig) as $candidate) {
            $path = trim($candidate['path']);
            if ($path === '') {
                continue;
            }

            $resolved = realpath($path);
            if ($resolved === false) {
                $failures[] = [
                    'binary_path' => $path,
                    'reason'      => 'missing',
                    'source'      => $candidate['source'],
                ];
                continue;
            }

            $normalized = $this->normalizePath($resolved);
            if (! in_array($normalized, $allowlist, true)) {
                $failures[] = [
                    'binary_path' => $normalized,
                    'reason'      => 'not_allowlisted',
                    'source'      => $candidate['source'],
                ];
                continue;
            }

            if (! is_executable($resolved)) {
                $failures[] = [
                    'binary_path' => $normalized,
                    'reason'      => 'not_executable',
                    'source'      => $candidate['source'],
                ];
                continue;
            }

            if ($candidate['source'] !== 'primary') {
                $this->logScan('debug', 'image_scan.clamav_binary_selected', [
                    'binary_path' => $normalized,
                    'source'      => $candidate['source'],
                ]);
            }

            return $resolved;
        }

        $this->logScan('error', 'image_scan.clamav_binary_unavailable', [
            'candidates' => $failures,
            'result'     => 'scan_failed',
        ]);

        return null;
    }

    /**
     * {@inheritDoc}
     */
    protected function sanitizeArguments(array|string|null $arguments, int $maxFileBytes): array
    {
        $tokens = $this->normalizeArgumentsToTokens($arguments);
        $sanitized = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = trim((string) $tokens[$i]);
            if ($token === '') {
                continue;
            }

            // Formato --nombre=valor
            if (str_contains($token, '=')) {
                [$name, $value] = explode('=', $token, 2);
                $name  = trim($name);
                $value = trim($value);
                $sanitized = $this->processArgumentPair($sanitized, $name, $value, $maxFileBytes);
                continue;
            }

            // Argumento simple
            if (! isset(self::ALLOWED_ARGUMENTS[$token])) {
                continue;
            }

            $definition = self::ALLOWED_ARGUMENTS[$token];
            if ($definition['expects'] === 'flag') {
                $sanitized[] = $token;
                continue;
            }

            // Argumento que espera un valor entero en el siguiente token
            $next = $tokens[$i + 1] ?? null;
            if ($next !== null && $this->isValidInteger($next)) {
                $sanitized[] = $token;
                $sanitized[] = (string) $this->clampIntegerArgument($token, (int) $next, $maxFileBytes);
                $i++; // saltar valor ya procesado
            }
        }

        return $sanitized;
    }

    /**
     * {@inheritDoc}
     *
     * Construye el comando para ClamAV.
     * - Si el binario es clamdscan → modo stream (usa el handle directamente).
     * - Si el binario es clamscan → crea una copia temporal mediante copyStreamToTemp().
     */
    protected function buildCommand(
        string $binary,
        array $arguments,
        array $target,
        array $scanConfig,
        array $scannerConfig,
    ): ?array {
        if (! isset($target['handle']) || ! is_resource($target['handle'])) {
            $this->logScan('error', 'image_scan.clamav_invalid_target_handle', [
                'target' => array_keys($target),
            ]);
            return ['fail_open_reason' => AntivirusException::REASON_TARGET_HANDLE_INVALID];
        }

        if (! isset($target['display_name']) || ! is_string($target['display_name'])) {
            $this->logScan('error', 'image_scan.clamav_invalid_target_display_name');
            return ['fail_open_reason' => AntivirusException::REASON_TARGET_MISSING_DISPLAY_NAME];
        }

        $maxFileBytes = $this->resolveMaxFileBytes();
        [$finalArguments, $isDaemon] = $this->ensureStreamArguments($arguments, $binary, $maxFileBytes);

        if ($isDaemon) {
            // clamdscan: escaneo por stream (stdin)
            return [
                'command'             => array_merge([$binary], $finalArguments, ['-']),
                'input'               => $target['handle'],
                'uses_target_handle'  => true,
                // No cerramos el handle; el padre es responsable.
            ];
        }

        // clamscan: requiere copia temporal del archivo
        $tempPath = $this->copyStreamToTemp(
            $target['handle'],
            'clam_scan_',
            $maxFileBytes > 0 ? $maxFileBytes : null,
            self::STREAM_COPY_TIMEOUT
        );

        if ($tempPath === null) {
            $this->logScan('error', 'image_scan.clamav_temp_copy_failed', [
                'file' => $target['display_name'],
            ]);
            return null;
        }

        return [
            'command'      => array_merge([$binary], $finalArguments, [$tempPath]),
            'input'        => null,
            'cleanup'      => function () use ($tempPath, $target): void {
                if (file_exists($tempPath) && ! unlink($tempPath)) {
                    $this->logScan('warning', 'image_scan.clamav_temp_cleanup_failed', [
                        'file'      => $target['display_name'],
                        'temp_path' => $tempPath,
                    ]);
                }
            },
            'close_target' => true, // El handle original puede cerrarse tras la copia
        ];
    }

    /* -------------------------------------------------------------------------
     |  Métodos privados de soporte
     ------------------------------------------------------------------------- */

    /**
     * Normaliza los argumentos a una lista plana de strings.
     *
     * @param array<string, mixed>|string|null $arguments
     * @return list<string>
     */
    private function normalizeArgumentsToTokens(array|string|null $arguments): array
    {
        $tokens = [];

        if (is_array($arguments)) {
            // Precomputar claves permitidas para búsqueda O(1)
            static $allowedKeys = null;
            if ($allowedKeys === null) {
                $allowedKeys = array_flip(array_keys(self::ALLOWED_ARGUMENTS));
            }

            foreach ($arguments as $key => $value) {
                if (is_int($key) && is_string($value)) {
                    $tokens[] = trim($value);
                } elseif (is_string($key) && isset($allowedKeys[$key])) {
                    if ($value === true || $value === null) {
                        $tokens[] = $key; // bandera
                    } elseif (is_scalar($value)) {
                        $tokens[] = $key . '=' . (string) $value;
                    }
                }
            }
        } elseif (is_string($arguments)) {
            $trimmed = trim($arguments);
            if ($trimmed !== '') {
                $tokens = preg_split('/\s+/', $trimmed) ?: [];
            }
        }

        return $tokens;
    }

    /**
     * Procesa un argumento en formato --nombre=valor.
     *
     * @param list<string> $sanitized
     * @param string $name
     * @param string $value
     * @param int $maxFileBytes
     * @return list<string>
     */
    private function processArgumentPair(array $sanitized, string $name, string $value, int $maxFileBytes): array
    {
        if (! isset(self::ALLOWED_ARGUMENTS[$name])) {
            return $sanitized;
        }

        $definition = self::ALLOWED_ARGUMENTS[$name];

        if ($definition['expects'] === 'flag') {
            $this->logScan('debug', 'image_scan.clamav_flag_with_value_ignored', [
                'argument' => $name,
                'value'    => $value,
            ]);
            $sanitized[] = $name; // solo la bandera
            return $sanitized;
        }

        if ($definition['expects'] === 'int' && $this->isValidInteger($value)) {
            $sanitized[] = $name;
            $sanitized[] = (string) $this->clampIntegerArgument($name, (int) $value, $maxFileBytes);
        }

        return $sanitized;
    }

    /**
     * Ajusta los argumentos para garantizar compatibilidad con clamdscan/clamscan.
     *
     * @param list<string> $arguments
     * @param string $binary
     * @param int $maxFileBytes
     * @return array{0: list<string>, 1: bool}
     */
    private function ensureStreamArguments(array $arguments, string $binary, int $maxFileBytes): array
    {
        $isDaemon = $this->isClamDaemon($binary);
        $filtered = [];
        $hasStream = false;
        $hasMaxFilesize = false;
        $hasMaxScansize = false;

        foreach ($arguments as $argument) {
            if ($argument === '--fdpass' && $isDaemon) {
                continue; // no compatible con clamdscan en modo stream
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

        if ($isDaemon && ! $hasStream) {
            $filtered[] = '--stream';
        }

        if ($maxFileBytes > 0) {
            if (! $hasMaxFilesize) {
                $filtered[] = '--max-filesize';
                $filtered[] = (string) $maxFileBytes;
            }
            if (! $hasMaxScansize) {
                $filtered[] = '--max-scansize';
                $filtered[] = (string) $maxFileBytes;
            }
        }

        return [$filtered, $isDaemon];
    }

    /**
     * Limita un valor entero al rango definido y al tamaño máximo del archivo.
     */
    private function clampIntegerArgument(string $argument, int $value, int $maxFileBytes): int
    {
        $definition = self::ALLOWED_ARGUMENTS[$argument] ?? null;

        if ($definition && isset($definition['min'], $definition['max'])) {
            $value = max($definition['min'], min($definition['max'], $value));
        }

        if (in_array($argument, ['--max-filesize', '--max-scansize'], true) && $maxFileBytes > 0) {
            $value = min($value, $maxFileBytes);
        }

        return $value;
    }

    /**
     * Verifica si un valor es un entero positivo (>=1).
     */
    private function isValidInteger(mixed $value): bool
    {
        if (! is_string($value) && ! is_int($value)) {
            return false;
        }

        $stringValue = (string) $value;
        return $stringValue !== '' && ctype_digit($stringValue) && (int) $stringValue > 0;
    }

    /**
     * Determina si el binario corresponde a clamdscan (con caché).
     */
    private function isClamDaemon(string $binary): bool
    {
        if ($this->isDaemonCache === null) {
            $basename = strtolower(basename($binary));
            $this->isDaemonCache = str_contains($basename, 'clamd');
        }
        return $this->isDaemonCache;
    }

    /**
     * Genera la lista priorizada de binarios candidatos.
     *
     * @param array<string, mixed> $scannerConfig
     * @return list<array{path: string, source: string}>
     */
    private function binaryCandidates(array $scannerConfig): array
    {
        $candidates = [];
        $seen = [];

        $push = function (string $path, string $source) use (&$candidates, &$seen): void {
            $trimmed = trim($path);
            if ($trimmed === '' || isset($seen[$trimmed])) {
                return;
            }
            $seen[$trimmed] = true;
            $candidates[] = ['path' => $trimmed, 'source' => $source];
        };

        $primary = (string) ($scannerConfig['binary'] ?? '');
        if ($primary !== '') {
            $push($primary, 'primary');
        }

        $fallbacks = $scannerConfig['binary_fallbacks'] ?? [];
        if (is_array($fallbacks)) {
            foreach ($fallbacks as $fallback) {
                if (! is_string($fallback)) {
                    continue;
                }
                $source = str_contains(strtolower($fallback), 'clamd') ? 'clamdscan' : 'fallback';
                $push($fallback, $source);
            }
        }

        return $candidates;
    }
}