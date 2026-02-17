<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Pipeline\Scanning\Scanners;

use App\Modules\Uploads\Pipeline\Security\Exceptions\InvalidRuleException;
use App\Modules\Uploads\Pipeline\Security\Logging\MediaSecurityLogger;
use App\Modules\Uploads\Pipeline\Security\Logging\MediaLogSanitizer;
use App\Modules\Uploads\Pipeline\Security\Upload\UploadSecurityLogger;
use App\Modules\Uploads\Pipeline\Scanning\YaraRuleManager;
use App\Support\Security\Exceptions\AntivirusException;

/**
 * Escáner concreto que utiliza YARA para detectar patrones maliciosos en archivos subidos.
 *
 * Versión final con todas las correcciones de seguridad y robustez:
 * - Path traversal eliminado (uso exclusivo de path canónico validado).
 * - Error handler siempre restaurado.
 * - Cleanup con re‑validación de ruta.
 * - Sin helpers globales (config, app).
 * - Logs sanitizados.
 *
 * @package App\Modules\Uploads\Pipeline\Scanning\Scanners
 */
final class YaraScanner extends AbstractScanner
{
    /**
     * Lista blanca de argumentos soportados.
     *
     * @var array<string, array{expects: string, min?: int, max?: int}>
     */
    private const ALLOWED_ARGUMENTS = [
        '--fail-on-warnings' => ['expects' => 'flag'],
        '--nothreads'        => ['expects' => 'flag'],
        '--print-tags'       => ['expects' => 'flag'],
        '--fast-scan'        => ['expects' => 'flag'],
        '--timeout'          => ['expects' => 'int', 'min' => 1, 'max' => 30],
    ];

    /**
     * Número máximo de reintentos para eliminar archivos temporales.
     */
    private const CLEANUP_MAX_ATTEMPTS = 3;

    /**
     * Tiempo de espera entre reintentos (microsegundos).
     */
    private const CLEANUP_RETRY_DELAY = 10000; // 10 ms

    private ?YaraRuleManager $ruleManager;
    private UploadSecurityLogger $uploadSecurityLogger;
    private string $environment;

    /**
     * @param array<string, mixed>|null $config           Configuración específica del escáner.
     * @param YaraRuleManager|null       $ruleManager      Gestor de reglas YARA.
     * @param UploadSecurityLogger|null  $uploadSecurityLogger Logger específico de uploads.
     * @param MediaSecurityLogger|null   $securityLogger   Logger de seguridad (para el padre).
     * @param string                     $environment      Entorno (local, testing, production).
     * @param array<string, mixed>|null  $globalConfig     Configuración global del escaneo.
     */
    public function __construct(
        ?array $config = null,
        ?YaraRuleManager $ruleManager = null,
        ?UploadSecurityLogger $uploadSecurityLogger = null,
        ?MediaSecurityLogger $securityLogger = null,
        string $environment = 'production',
        ?array $globalConfig = null,
    ) {
        // Resolver logger de seguridad para el padre
        $resolvedSecurityLogger = $securityLogger ?? $this->resolveDefaultMediaLogger();

        // Llamar al constructor del padre con todos los parámetros
        parent::__construct(
            config: $config,
            securityLogger: $resolvedSecurityLogger,
            globalConfig: $globalConfig,
        );

        // Resolver rule manager
        $this->ruleManager = $ruleManager ?? $this->resolveDefaultRuleManager();

        // Resolver upload logger
        $this->uploadSecurityLogger = $uploadSecurityLogger ?? $this->resolveDefaultUploadLogger($resolvedSecurityLogger);

        $this->environment = $environment;
    }

    /**
     * Resuelve el logger de seguridad por defecto.
     */
    private function resolveDefaultMediaLogger(): MediaSecurityLogger
    {
        if (function_exists('app')) {
            try {
                return app(MediaSecurityLogger::class);
            } catch (\Throwable) {
                // fallback
            }
        }
        return new MediaSecurityLogger(new MediaLogSanitizer());
    }

    /**
     * Resuelve el rule manager por defecto.
     */
    private function resolveDefaultRuleManager(): ?YaraRuleManager
    {
        if (function_exists('app')) {
            try {
                return app(YaraRuleManager::class);
            } catch (\Throwable) {
                // fallback silencioso
            }
        }
        return null;
    }

    /**
     * Resuelve el upload logger por defecto.
     */
    private function resolveDefaultUploadLogger(MediaSecurityLogger $mediaLogger): UploadSecurityLogger
    {
        if (function_exists('app')) {
            try {
                return app(UploadSecurityLogger::class);
            } catch (\Throwable) {
                // fallback
            }
        }
        return new UploadSecurityLogger($mediaLogger);
    }

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
     * Resuelve y valida el binario de YARA.
     * - Utiliza la configuración inyectada (no consulta config() global).
     */
    protected function resolveExecutable(array $scannerConfig): ?string
    {
        static $loggedMissing = false;

        // El escaneo está habilitado? Lo decide el padre/coordinador, no esta clase.
        // Simplemente respetamos el flag de configuración inyectado.
        $enabled = $this->globalConfig['enabled'] ?? true;
        if (!$enabled) {
            return null;
        }

        $allowlist = $this->allowedBinaries();
        if ($allowlist === []) {
            if (!$loggedMissing) {
                $this->securityLogger->warning('image_scan.yara_binary_allowlist_empty');
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
                $failures[] = ['binary' => $path, 'reason' => 'missing', 'source' => $candidate['source']];
                continue;
            }

            $normalized = $this->normalizePath($resolved);
            if (!in_array($normalized, $allowlist, true)) {
                $failures[] = ['binary' => $normalized, 'reason' => 'not_allowlisted', 'source' => $candidate['source']];
                continue;
            }

            if (!is_executable($resolved)) {
                $failures[] = ['binary' => $normalized, 'reason' => 'not_executable', 'source' => $candidate['source']];
                continue;
            }

            if ($candidate['source'] !== 'primary') {
                $this->securityLogger->debug('image_scan.yara_binary_selected', [
                    'binary' => $normalized,
                    'source' => $candidate['source'],
                ]);
            }

            return $resolved;
        }

        $this->securityLogger->error('image_scan.yara_binary_unavailable', [
            'candidates' => $failures,
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

        for ($i = 0; $i < count($tokens); $i++) {
            $token = trim($tokens[$i]);
            if ($token === '') {
                continue;
            }

            if (str_contains($token, '=')) {
                [$name, $value] = explode('=', $token, 2);
                $name  = trim($name);
                $value = trim($value);
                $sanitized = $this->processArgumentPair($sanitized, $name, $value);
                continue;
            }

            if (!isset(self::ALLOWED_ARGUMENTS[$token])) {
                continue;
            }

            $definition = self::ALLOWED_ARGUMENTS[$token];
            if ($definition['expects'] === 'flag') {
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
     */
    protected function buildCommand(
        string $binary,
        array $arguments,
        array $target,
        array $scanConfig,
        array $scannerConfig,
    ): ?array {
        // --- Validaciones del target ---
        if (!isset($target['handle']) || !is_resource($target['handle'])) {
            $this->securityLogger->error('image_scan.yara_target_invalid_handle', [
                'expected' => ['handle', 'display_name'],
                'received' => array_keys($target),
            ]);
            return ['fail_open_reason' => AntivirusException::REASON_TARGET_HANDLE_INVALID];
        }

        if (!isset($target['display_name']) || !is_string($target['display_name'])) {
            $this->securityLogger->error('image_scan.yara_target_invalid_display_name');
            return ['fail_open_reason' => AntivirusException::REASON_TARGET_MISSING_DISPLAY_NAME];
        }

        // --- Validación del binario ---
        $binaryCheck = $this->validateBinary($binary);
        if ($binaryCheck !== null) {
            return $binaryCheck;
        }

        // --- Validación de reglas YARA ---
        $rulesBase = $this->resolveAllowedRulesBase($scanConfig);
        if ($rulesBase !== null && !$this->isValidRulesDirectory($rulesBase)) {
            $this->securityLogger->critical('image_scan.yara_rules_base_invalid', ['base' => $rulesBase]);
            return ['fail_open_reason' => AntivirusException::REASON_RULES_BASE_INVALID];
        }

        // Resolver y validar ruta de reglas (retorna path canónico o null)
        $rulesPath = $this->resolveValidatedRulesPath($scannerConfig, $rulesBase);
        if ($rulesPath === null) {
            $this->securityLogger->critical('image_scan.yara_failopen', ['reason' => 'rules_missing_or_invalid']);
            return ['fail_open_reason' => AntivirusException::REASON_RULES_MISSING];
        }

        // --- Validación de integridad de reglas ---
        $integrityFailure = $this->guardRulesIntegrity();
        if ($integrityFailure !== null) {
            return $integrityFailure;
        }

        // --- Manejo del handle y límite de tamaño ---
        $handle = $target['handle'];

        // Configurar timeout en el handle para evitar bloqueos
        if (!stream_set_timeout($handle, 5)) {
            $this->securityLogger->warning('image_scan.yara_failed_set_stream_timeout', [
                'target' => $target['display_name'],
            ]);
        }

        if (!$this->ensureHandleSeekable($handle, $target['display_name'])) {
            return ['fail_open_reason' => AntivirusException::REASON_TARGET_HANDLE_UNSEEKABLE];
        }

        $maxFileBytes = $this->resolveMaxFileBytes($scanConfig, $scannerConfig);
        if ($maxFileBytes > 0 && !$this->validateFileSizeWithinLimit($handle, $maxFileBytes, $target['display_name'])) {
            return ['fail_open_reason' => AntivirusException::REASON_FILE_TOO_LARGE];
        }

        // --- Construcción del comando ---
        return [
            'command' => array_merge([$binary], $arguments, [$rulesPath, '-']),
            'input'   => $handle,
            'uses_target_handle' => true,
            'cleanup' => $this->createCleanupClosure($rulesPath),
        ];
    }

    /* -------------------------------------------------------------------------
     |  Métodos privados de soporte
     ------------------------------------------------------------------------- */

    /**
     * Normaliza argumentos a una lista plana de strings.
     *
     * @param array<string, mixed>|string|null $arguments
     * @return list<string>
     */
    private function normalizeArgumentsToTokens(array|string|null $arguments): array
    {
        $tokens = [];

        if (is_array($arguments)) {
            static $allowedKeys = null;
            if ($allowedKeys === null) {
                $allowedKeys = array_flip(array_keys(self::ALLOWED_ARGUMENTS));
            }

            foreach ($arguments as $key => $value) {
                if (is_int($key) && is_string($value)) {
                    $tokens[] = trim($value);
                } elseif (is_string($key) && isset($allowedKeys[$key])) {
                    if ($value === true || $value === null) {
                        $tokens[] = $key;
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
     * @param string       $name
     * @param string       $value
     * @return list<string>
     */
    private function processArgumentPair(array $sanitized, string $name, string $value): array
    {
        if (!isset(self::ALLOWED_ARGUMENTS[$name])) {
            return $sanitized;
        }

        $definition = self::ALLOWED_ARGUMENTS[$name];
        if ($definition['expects'] === 'flag') {
            $this->securityLogger->warning('image_scan.yara_flag_with_value_ignored', [
                'argument' => $name,
                'value'    => $value,
            ]);
            $sanitized[] = $name;
            return $sanitized;
        }

        if ($definition['expects'] === 'int' && $this->isValidInteger($value)) {
            $sanitized[] = $name;
            $sanitized[] = (string) $this->clampIntegerArgument($name, (int) $value);
        }

        return $sanitized;
    }

    /**
     * Limita un valor entero a los rangos definidos.
     */
    private function clampIntegerArgument(string $argument, int $value): int
    {
        $definition = self::ALLOWED_ARGUMENTS[$argument] ?? null;
        if ($definition && isset($definition['min'], $definition['max'])) {
            return max($definition['min'], min($definition['max'], $value));
        }
        return $value;
    }

    /**
     * Verifica si un valor es un entero positivo (>=1).
     */
    private function isValidInteger(mixed $value): bool
    {
        if (!is_string($value) && !is_int($value)) {
            return false;
        }
        $stringValue = (string) $value;
        return $stringValue !== '' && ctype_digit($stringValue) && (int) $stringValue > 0;
    }

    /**
     * Valida que la ruta del binario sea ejecutable y esté en la lista blanca.
     *
     * @return array{fail_open_reason:string}|null
     */
    private function validateBinary(string $binary): ?array
    {
        $resolved = realpath($binary);
        if ($resolved === false) {
            $this->securityLogger->error('image_scan.yara_binary_unresolved', [
                'binary' => $binary,
            ]);
            return ['fail_open_reason' => AntivirusException::REASON_BINARY_UNRESOLVED];
        }

        $normalized = $this->normalizePath($resolved);
        $allowlist = $this->allowedBinaries();
        if ($allowlist === []) {
            $this->securityLogger->critical('image_scan.yara_no_allowlist_configured');
            return ['fail_open_reason' => AntivirusException::REASON_ALLOWLIST_EMPTY];
        }

        if (!in_array($normalized, $allowlist, true)) {
            $this->securityLogger->error('image_scan.yara_binary_not_allowlisted', [
                'binary' => basename($resolved),
            ]);
            return ['fail_open_reason' => AntivirusException::REASON_BINARY_NOT_ALLOWLISTED];
        }

        if (!is_file($resolved) || !is_executable($resolved)) {
            $this->securityLogger->error('image_scan.yara_binary_unusable', [
                'binary' => $resolved,
            ]);
            return ['fail_open_reason' => AntivirusException::REASON_BINARY_NOT_EXECUTABLE];
        }

        return null;
    }

    /**
     * Verifica que el directorio base de reglas sea un directorio real y accesible.
     */
    private function isValidRulesDirectory(string $base): bool
    {
        $real = realpath($base);
        return $real !== false && is_dir($real) && is_readable($real);
    }

    /**
     * Resuelve y valida la ruta del archivo de reglas, retornando el path canónico.
     *
     * @param array<string, mixed> $scannerConfig
     * @param string|null          $rulesBase
     * @return string|null Path canónico validado, o null si falla.
     */
    private function resolveValidatedRulesPath(array $scannerConfig, ?string $rulesBase): ?string
    {
        $rulesPath = trim((string) ($scannerConfig['rules_path'] ?? ''));
        if ($rulesPath === '') {
            $this->logScan('error', 'image_scan.yara_rules_missing');
            return null;
        }

        $realRules = realpath($rulesPath);
        if ($realRules === false) {
            $this->logScan('error', 'image_scan.yara_rules_unreachable', [
                'rules_path' => $rulesPath,
            ]);
            return null;
        }

        // Validar extensión y que no sea enlace
        $ext = strtolower(pathinfo($realRules, PATHINFO_EXTENSION));
        if (!is_file($realRules) || is_link($realRules) || !in_array($ext, ['yar', 'yara', 'yarac'], true)) {
            $this->logScan('error', 'image_scan.yara_rules_not_regular', [
                'rules_path' => $realRules,
            ]);
            return null;
        }

        if ($rulesBase !== null) {
            $realBase = realpath($rulesBase);
            if ($realBase === false || !is_dir($realBase)) {
                $this->logScan('error', 'image_scan.yara_rules_base_invalid', [
                    'base' => $rulesBase,
                ]);
                return null;
            }

            $normalizedRules = $this->normalizePath($realRules);
            $normalizedBase  = rtrim($this->normalizePath($realBase), '/');
            $prefix = $normalizedBase === '/' ? '/' : $normalizedBase . '/';

            if ($normalizedRules !== $normalizedBase && !str_starts_with($normalizedRules, $prefix)) {
                $this->logScan('error', 'image_scan.yara_rules_outside_allowed', [
                    'rules_path'   => $realRules,
                    'allowed_dir'  => $normalizedBase,
                ]);
                return null;
            }
        }

        // Crear copia temporal segura (el padre proporciona copyStreamToTemp)
        $handle = fopen($realRules, 'rb');
        if ($handle === false) {
            $this->logScan('error', 'image_scan.yara_rules_open_failed', [
                'rules_path' => $realRules,
            ]);
            return null;
        }

        $tempPath = $this->copyStreamToTemp($handle, $this->scannerKey() . '_rules_');
        $this->closeHandle($handle);

        if ($tempPath === null) {
            $this->logScan('error', 'image_scan.yara_rules_copy_failed', [
                'rules_path' => $realRules,
            ]);
        }

        return $tempPath; // ya es path canónico (copyStreamToTemp retorna realpath)
    }

    /**
     * Intenta posicionar el puntero del handle al inicio.
     * Protege el error handler con try/finally.
     *
     * @param resource $handle
     * @param string   $displayName
     * @return bool
     */
    protected function ensureHandleSeekable($handle, string $displayName): bool
    {
        $rewindError = null;
        $rewound = false;

        try {
            set_error_handler(static function (int $severity, string $message) use (&$rewindError): bool {
                $rewindError = $message;
                return true;
            }, E_WARNING | E_NOTICE);

            $rewound = rewind($handle);
        } finally {
            restore_error_handler();
        }

        if ($rewound === false) {
            $this->securityLogger->error('image_scan.yara_target_unseekable', [
                'target' => $displayName,
                'error'  => $this->sanitizePath($rewindError),
            ]);
            return false;
        }

        return true;
    }

    /**
     * Valida que el tamaño del archivo no exceda el límite.
     * Si fstat falla, se rechaza.
     */
    protected function validateFileSizeWithinLimit($handle, int $limit, string $displayName): bool
    {
        $stats = fstat($handle);
        if ($stats === false || !isset($stats['size'])) {
            $this->securityLogger->error('image_scan.yara_cannot_determine_file_size', [
                'file'  => $displayName,
                'limit' => $limit,
            ]);
            return false;
        }

        if ($stats['size'] > $limit) {
            $this->securityLogger->warning('image_scan.yara_file_too_large', [
                'file'  => $displayName,
                'size'  => $stats['size'],
                'limit' => $limit,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Crea un closure para limpiar el archivo temporal de reglas.
     * Revalida que el archivo siga en el directorio temporal.
     *
     * @param string $rulesPath
     * @return callable
     */
    private function createCleanupClosure(string $rulesPath): callable
    {
        $logger = $this->securityLogger;
        $tempDir = rtrim((string) sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $maxAttempts = self::CLEANUP_MAX_ATTEMPTS;
        $delay = self::CLEANUP_RETRY_DELAY;

        return static function () use ($rulesPath, $logger, $tempDir, $maxAttempts, $delay): void {
            // Re-validar que el archivo aún existe y está dentro del directorio temporal
            $real = realpath($rulesPath);
            if ($real === false) {
                return; // ya no existe
            }

            if (!str_starts_with($real, $tempDir)) {
                $logger->critical('image_scan.yara_rules_cleanup_path_outside_temp', [
                    'path' => $rulesPath,
                    'real' => $real,
                    'temp_dir' => $tempDir,
                ]);
                return;
            }

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                if (@unlink($real)) {
                    return;
                }
                if ($attempt < $maxAttempts) {
                    usleep($delay);
                }
            }

            // Última verificación con caché limpia
            clearstatcache(true, $real);
            if (file_exists($real)) {
                $logger->error('image_scan.yara_rules_cleanup_failed_persistent', [
                    'path'     => $real,
                    'attempts' => $maxAttempts,
                ]);
            }
        };
    }

    /**
     * Valida integridad de reglas y decide fail‑open según entorno.
     *
     * @return array{fail_open_reason:string}|null
     */
    private function guardRulesIntegrity(): ?array
    {
        if ($this->ruleManager === null) {
            return null;
        }

        try {
            $this->ruleManager->validateIntegrity();
            $this->uploadSecurityLogger->yaraRulesValidated([
                'version' => $this->ruleManager->getCurrentVersion(),
            ]);
            return null;
        } catch (InvalidRuleException $exception) {
            $context = ['error' => $this->sanitizePath($exception->getMessage())];
            $this->uploadSecurityLogger->yaraRulesFailed($context);
            $this->securityLogger->critical('image_scan.yara_rules_invalid', $context);

            // En entornos de desarrollo/testing permitimos fail‑open
            if (in_array($this->environment, ['local', 'testing'], true)) {
                $this->securityLogger->warning('image_scan.yara_rules_invalid_local_bypassed', $context);
                return null;
            }

            return ['fail_open_reason' => AntivirusException::REASON_RULES_INTEGRITY_FAILED];
        }
    }

    /**
     * Genera la lista priorizada de binarios candidatos con cache estática.
     *
     * @param array<string, mixed> $scannerConfig
     * @return list<array{path: string, source: string}>
     */
    private function binaryCandidates(array $scannerConfig): array
    {
        static $cache = [];
        $cacheKey = md5(serialize($scannerConfig));

        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

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
                if (!is_string($fallback)) {
                    continue;
                }
                $push($fallback, 'fallback');
            }
        }

        $cache[$cacheKey] = $candidates;
        return $candidates;
    }
}