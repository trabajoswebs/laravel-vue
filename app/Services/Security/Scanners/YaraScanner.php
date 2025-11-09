<?php

declare(strict_types=1);

namespace App\Services\Security\Scanners;

use Illuminate\Support\Facades\Log;

/**
 * Escáner concreto que utiliza YARA para detectar patrones o firmas en archivos subidos.
 *
 * Esta clase extiende `AbstractScanner` e implementa la lógica específica para interactuar
 * con el motor de escaneo YARA. Define argumentos permitidos, sanitiza entradas,
 * y construye el comando de YARA correspondiente, generalmente leyendo el archivo
 * objetivo desde stdin ('-') y usando un archivo de reglas específico.
 */
final class YaraScanner extends AbstractScanner
{
    /**
     * Lista blanca de argumentos soportados y tipo esperado.
     *
     * @var array<string, array{expects: string}> Clave: nombre del argumento, Valor: tipo esperado ('flag' o 'int').
     */
    private const ALLOWED_ARGUMENTS = [
        '--fail-on-warnings' => ['expects' => 'flag'], // Bandera que no requiere valor.
        '--nothreads'        => ['expects' => 'flag'],
        '--print-tags'       => ['expects' => 'flag'],
        '--fast-scan'        => ['expects' => 'flag'],
        '--timeout'          => ['expects' => 'int'],  // Argumento que requiere un valor entero.
    ];

    /**
     * Rangos seguros para argumentos enteros.
     *
     * @var array<string, array{min: int, max: int}> Clave: nombre del argumento, Valor: rango permitido.
     */
    private const INTEGER_RANGES = [
        '--timeout' => ['min' => 1, 'max' => 30], // Segundos
    ];

    /**
     * {@inheritDoc}
     */
    protected function scannerKey(): string
    {
        return 'yara';
    }

    /**
     * {@inheritDoc}
     *
     * Sanitiza y filtra los argumentos para el comando de YARA.
     * Asegura que solo se pasen argumentos permitidos y con formato correcto (banderas o enteros).
     * Limita los valores enteros a rangos seguros.
     *
     * @param array<string, mixed>|string|null $arguments Argumentos sin procesar.
     * @param int $maxFileBytes Tamaño máximo del archivo (no usado por YARA).
     * @return list<string> Lista de argumentos sanitizados y seguros.
     */
    protected function sanitizeArguments(array|string|null $arguments, int $maxFileBytes): array
    {
        unset($maxFileBytes); // YARA no usa max bytes en argumentos

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
                    Log::warning('image_scan.yara_flag_with_value_ignored', ['argument' => $name]);
                    continue;
                }

                if ($expects === 'int' && $this->isValidInteger($value)) {
                    $sanitized[] = $name;
                    $sanitized[] = (string) $this->clampIntegerArgument($name, (int) $value);
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
                $sanitized[] = (string) $this->clampIntegerArgument($token, (int) $next);
                $i++;
            }
        }

        return $sanitized;
    }

    /**
     * {@inheritDoc}
     *
     * Construye el comando específico de YARA.
     * Valida y prepara el archivo de reglas YARA, y configura el comando para leer
     * el archivo objetivo desde stdin ('-').
     *
     * @param string $binary Ruta al binario de YARA.
     * @param array<string, string> $arguments Argumentos sanitizados.
     * @param array<string, mixed> $target Información sobre el archivo objetivo (path, handle, etc.).
     * @param array<string, mixed> $scanConfig Configuración global del escaneo.
     * @param array<string, mixed> $scannerConfig Configuración específica del escáner.
     * @return array{command: list<string>, input: resource|null, cleanup: (callable|null), uses_target_handle?: bool, target_closed?: bool, fail_open_reason?: string}|null
     *         Array con el comando, handle de entrada opcional, función de limpieza, flags de estado
     *         y razón de fail-open opcional, o `null` si la construcción falla.
     */
    protected function buildCommand(
        string $binary,
        array $arguments,
        array $target,
        array $scanConfig,
        array $scannerConfig,
    ): ?array {
        if (! isset($target['handle']) || ! is_resource($target['handle'])) {
            Log::error('image_scan.yara_target_invalid_handle', [
                'expected_keys' => ['handle', 'display_name'],
                'received_keys' => array_keys($target),
            ]);
            return [
                'fail_open_reason' => 'target_handle_invalid',
            ];
        }

        if (! isset($target['display_name']) || ! is_string($target['display_name'])) {
            Log::error('image_scan.yara_target_invalid_display_name');
            return [
                'fail_open_reason' => 'target_missing_display_name',
            ];
        }

        $resolvedBinary = realpath($binary);
        if ($resolvedBinary === false || $resolvedBinary === '') {
            Log::error('image_scan.yara_binary_unresolved', ['binary' => $binary]);
            return null;
        }

        $normalizedBinary = $this->normalizePath($resolvedBinary);
        $allowlist = $this->allowedBinaries();
        if ($allowlist === []) {
            Log::critical('image_scan.yara_no_allowlist_configured');
            return null;
        }

        if (! in_array($normalizedBinary, $allowlist, true)) {
            Log::error('image_scan.yara_binary_not_allowlisted', ['binary' => basename($resolvedBinary)]);
            return null;
        }

        if (! is_file($resolvedBinary) || ! is_executable($resolvedBinary)) {
            Log::error('image_scan.yara_binary_unusable', ['binary' => $resolvedBinary]);
            return null;
        }

        $binary = $resolvedBinary;

        // Valida y obtiene el directorio base permitido para reglas.
        $rulesBase = $this->resolveAllowedRulesBase($scanConfig);
        // Valida, copia a un temporal seguro y obtiene la ruta del archivo de reglas.
        $rulesPath = $this->resolveRulesPath($scannerConfig, $rulesBase);

        if ($rulesPath === null) {
            // Si no se puede obtener el archivo de reglas, se indica un fail_open_reason.
            Log::critical('image_scan.yara_failopen', ['reason' => 'rules_missing']);
            return [
                'fail_open_reason' => 'rules_missing',
            ];
        }

        if (! $this->rulesPathWithinBase($rulesPath, $rulesBase)) {
            Log::critical('image_scan.yara_rules_path_validation_failed', [
                'rules_path' => $rulesPath,
                'base' => $rulesBase,
            ]);
            return [
                'fail_open_reason' => 'rules_path_invalid',
            ];
        }

        $handle = $target['handle'];
        $rewindError = null;
        set_error_handler(static function (int $severity, string $message) use (&$rewindError): bool {
            $rewindError = $message;
            return true;
        }, E_WARNING | E_NOTICE);
        $rewound = rewind($handle);
        restore_error_handler();

        if ($rewound === false) {
            Log::error('image_scan.yara_target_unseekable', [
                'target' => $target['display_name'],
                'error' => $rewindError,
            ]);
            return [
                'fail_open_reason' => 'target_handle_unseekable',
            ];
        }

        $maxFileBytes = $this->resolveMaxFileBytes($scanConfig, $scannerConfig);
        if ($maxFileBytes > 0) {
            $stats = @fstat($handle);
            if (
                is_array($stats)
                && isset($stats['size'])
                && is_int($stats['size'])
                && $stats['size'] > $maxFileBytes
            ) {
                Log::warning('image_scan.yara_file_too_large', [
                    'file' => $target['display_name'],
                    'size' => $stats['size'],
                    'limit' => $maxFileBytes,
                ]);
                return [
                    'fail_open_reason' => 'file_too_large',
                ];
            }
        }

        // Construye el comando: yara [arguments] [rules_file] -
        // Nota: la responsabilidad de cerrar el handle permanece en el llamador cuando uses_target_handle es true.
        return [
            'command' => array_merge([$binary], $arguments, [$rulesPath, '-']), // '-' indica lectura desde stdin
            'input' => $handle, // El handle del archivo objetivo se pasa como entrada al proceso.
            'uses_target_handle' => true, // El proceso usará el handle directamente.
            'cleanup' => static function () use ($rulesPath): void {
                // Define una función de limpieza para eliminar el archivo temporal de reglas.
                $attempts = 0;
                while ($attempts < 3) {
                    if (@unlink($rulesPath)) {
                        return;
                    }

                    $attempts++;
                    usleep(10000);
                }

                clearstatcache(true, $rulesPath);
                if (file_exists($rulesPath)) {
                    Log::error('image_scan.yara_rules_cleanup_failed_persistent', [
                        'path' => $rulesPath,
                        'attempts' => $attempts,
                    ]);
                }
            },
        ];
    }

    /**
     * Verifica que la ruta de reglas esté dentro del directorio permitido y sea legible.
     *
     * @param string $rulesPath Ruta del archivo de reglas temporal.
     * @param string|null $rulesBase Directorio base permitido.
     * @return bool
     */
    private function rulesPathWithinBase(string $rulesPath, ?string $rulesBase): bool
    {
        $realRules = realpath($rulesPath);
        if ($realRules === false) {
            return false;
        }

        if (! is_file($realRules) || ! is_readable($realRules)) {
            return false;
        }

        if ($rulesBase === null) {
            return true;
        }

        $realBase = realpath($rulesBase);
        if ($realBase === false) {
            return false;
        }

        $normalizedRules = $this->normalizePath($realRules);
        $normalizedBase = rtrim($this->normalizePath($realBase), '/');
        $prefix = $normalizedBase === '' ? '/' : $normalizedBase . '/';

        return $normalizedRules === $normalizedBase || str_starts_with($normalizedRules, $prefix);
    }

    /**
     * Limita un valor entero de un argumento a rangos seguros definidos en INTEGER_RANGES.
     *
     * @param string $argument Nombre del argumento (por ejemplo, '--timeout').
     * @param int $value Valor original.
     * @return int Valor ajustado.
     */
    private function clampIntegerArgument(string $argument, int $value): int
    {
        if (! isset(self::INTEGER_RANGES[$argument])) {
            return $value;
        }

        $min = self::INTEGER_RANGES[$argument]['min'];
        $max = self::INTEGER_RANGES[$argument]['max'];

        return max($min, min($max, $value)); // Ej.: timeout=120 → clamp a 30
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
}
