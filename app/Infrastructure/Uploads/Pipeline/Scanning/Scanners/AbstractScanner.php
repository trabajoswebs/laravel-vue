<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Scanning\Scanners;

use App\Support\Security\Exceptions\AntivirusException;
use App\Infrastructure\Uploads\Pipeline\Security\Logging\MediaSecurityLogger;
use App\Infrastructure\Uploads\Pipeline\Security\Logging\MediaLogSanitizer;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Plantilla base abstracta para escáneres de seguridad de archivos.
 *
 * Esta clase encapsula la lógica común de escaneo con procesos externos,
 * asegurando:
 * - Inyección completa de dependencias (sin helpers globales).
 * - Gestión responsable de recursos (handles).
 * - Copia segura de streams con timeout, límite de tamaño y permisos estrictos.
 * - Logs sanitizados (sin exposición de rutas del sistema).
 * - Política de fail‑open/fail‑closethrowable.
 *
 * @package App\Infrastructure\Uploads\Pipeline\Scanning\Scanners
 */
abstract class AbstractScanner
{
    /**
     * Número máximo de intentos para crear un archivo temporal exclusivo.
     */
    protected const TEMP_FILE_MAX_ATTEMPTS = 5;

    /**
     * Prefijo por defecto para archivos temporales.
     */
    protected const DEFAULT_TEMP_PREFIX = 'scan_';

    /**
     * Tamaño de truncamiento para salida stdout/stderr (caracteres).
     */
    protected const OUTPUT_TRUNCATE_SIZE = 1000;

    /**
     * Tamaño del preview en modo debug (caracteres).
     */
    protected const PREVIEW_TRUNCATE_SIZE = 600;

    /**
     * Tamaño de fragmento para copia de streams (1 MB).
     */
    protected const STREAM_CHUNK_SIZE = 1048576;

    /**
     * Número máximo de lecturas vacías consecutivas.
     */
    protected const MAX_EMPTY_READS = 20;

    /**
     * Tiempo máximo total para copiar un stream (segundos).
     */
    protected const STREAM_COPY_TIMEOUT = 30;

    protected MediaSecurityLogger $securityLogger;

    /**
     * Configuración global del escaneo (image-pipeline.scan).
     *
     * @var array<string, mixed>
     */
    protected array $globalConfig;

    /**
     * Configuración específica del escáner (image-pipeline.scan.{scannerKey}).
     *
     * @var array<string, mixed>
     */
    protected array $scannerConfig;

    protected array $currentLogContext = [];
    protected ?float $currentScanStartedAt = null;
    protected bool $currentStrictMode = false;

    /**
     * Constructor con inyección total de dependencias.
     *
     * @param MediaSecurityLogger       $securityLogger   Logger de seguridad (obligatorio).
     * @param array<string, mixed>      $globalConfig     Configuración global del escaneo.
     * @param array<string, mixed>|null $scannerConfig    Configuración específica del escáner.
     */
    public function __construct(
        protected readonly ?array $config = null,
        ?MediaSecurityLogger $securityLogger = null,
        ?array $globalConfig = null,
        ?array $scannerConfig = null
    ) {
        $this->securityLogger = $securityLogger ?? $this->resolveDefaultLogger();
        $this->globalConfig = $globalConfig ?? $this->resolveDefaultGlobalConfig();
        $this->scannerConfig = $scannerConfig ?? [];
    }

    private function resolveDefaultLogger(): MediaSecurityLogger
    {
        if (function_exists('app')) {
            try {
                return app(MediaSecurityLogger::class);
            } catch (\Throwable) {
                // fallback below
            }
        }

        return new MediaSecurityLogger(new MediaLogSanitizer());
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveDefaultGlobalConfig(): array
    {
        if (function_exists('config')) {
            try {
                return (array) config('image-pipeline.scan', []);
            } catch (\Throwable) {
                // fallback below
            }
        }

        return [];
    }

    /**
     * Ejecuta el escaneo de un archivo subido.
     *
     * @param UploadedFile $file    Archivo a escanear.
     * @param array<string, mixed> $context Contexto (upload_id, tenant_id, path, etc.).
     * @return bool `true` si el archivo es limpio, `false` si se detecta malware o error no recuperable.
     * @throws AntivirusException Si el modo estricto está activado y ocurre un fallo de configuración/validación.
     */
    final public function __invoke(UploadedFile $file, array $context): bool
    {
        if (! ($context['is_first_chunk'] ?? false)) {
            return true;
        }

        $this->resetPerScanState();
        $this->currentLogContext = $this->extractLogContext($context);
        $this->currentStrictMode = (bool) ($this->globalConfig['strict'] ?? true);

        $scannerConfig = array_merge(
            (array) ($this->globalConfig[$this->scannerKey()] ?? []),
            $this->scannerConfig,
            $this->config ?? []
        );

        // 1. Resolver binario
        $binary = $this->resolveExecutable($scannerConfig);
        if ($binary === null) {
            return $this->failOpen($this->currentStrictMode, AntivirusException::REASON_BINARY_MISSING);
        }

        $maxFileBytes = $this->resolveMaxFileBytes();

        // 2. Resolver archivo objetivo
        $target = $this->resolveTarget($context, $maxFileBytes);
        if ($target === null) {
            return false;
        }

        // 3. Sanitizar argumentos
        $arguments = $this->sanitizeArguments(
            $scannerConfig['arguments'] ?? null,
            $maxFileBytes
        );

        // 4. Construir comando
        $build = $this->buildCommand(
            $binary,
            $arguments,
            $target,
            $this->globalConfig,
            $scannerConfig
        );

        if ($build === null) {
            $this->closeHandle($target['handle'] ?? null);
            return $this->failOpen($this->currentStrictMode, AntivirusException::REASON_BUILD_FAILED);
        }

        // 5. Manejar fallos indicados por el builder
        if (isset($build['fail_open_reason'])) {
            $result = $this->failOpen($this->currentStrictMode, (string) $build['fail_open_reason']);

            if (($build['close_target'] ?? false) && isset($target['handle'])) {
                $this->closeHandle($target['handle']);
            }
            $this->safelyInvokeCleanup($build['cleanup'] ?? null);
            return $result;
        }

        if (empty($build['command'])) {
            if (($build['close_target'] ?? false) && isset($target['handle'])) {
                $this->closeHandle($target['handle']);
            }
            $this->safelyInvokeCleanup($build['cleanup'] ?? null);
            return false;
        }

        $command = $build['command'];
        $inputHandle = $build['input'] ?? null;
        $usesTargetHandle = (bool) ($build['uses_target_handle'] ?? false);

        // 6. Preparar proceso
        $process = new Process($command);
        $timeout = (float) ($scannerConfig['timeout'] ?? $this->globalConfig['timeout'] ?? 10);
        $idleTimeout = (float) ($this->globalConfig['idle_timeout'] ?? $timeout);

        if ($timeout > 0) {
            $process->setTimeout($timeout);
        }
        if ($idleTimeout > 0) {
            $process->setIdleTimeout($idleTimeout);
        }

        // 7. Configurar entrada estándar
        if (is_resource($inputHandle)) {
            if (! $this->ensureHandleSeekable($inputHandle, $target['display_name'] ?? 'unknown')) {
                $this->closeHandle($inputHandle);
                $this->safelyInvokeCleanup($build['cleanup'] ?? null);
                return $this->failOpen($this->currentStrictMode, AntivirusException::REASON_TARGET_HANDLE_UNSEEKABLE);
            }
            $process->setInput($inputHandle);
        }

        // 8. Ejecutar proceso
        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            $this->logScan('error', sprintf('image_scan.%s_timeout', $this->scannerKey()), [
                'tmp_path' => $target['display_name'],
                'result'   => 'scan_failed',
                'error'    => $this->sanitizePath($exception->getMessage()),
            ]);
            return $this->failOpen($this->currentStrictMode, AntivirusException::REASON_PROCESS_TIMEOUT);
        } catch (Throwable $exception) {
            $this->logScan('error', sprintf('image_scan.%s_exception', $this->scannerKey()), [
                'tmp_path' => $target['display_name'],
                'result'   => 'scan_failed',
                'error'    => $this->sanitizePath($exception->getMessage()),
            ]);
            return $this->failOpen($this->currentStrictMode, AntivirusException::REASON_PROCESS_EXCEPTION);
        } finally {
            // Cerrar el handle de entrada si no fue usado por el proceso o si el hijo solicitó cierre
            if (! $usesTargetHandle || ($build['close_target'] ?? false)) {
                $this->closeHandle($inputHandle);
                $this->closeHandle($target['handle'] ?? null);
            }
            $this->safelyInvokeCleanup($build['cleanup'] ?? null);
        }

        // 9. Interpretar resultado
        return $this->interpretExitCode(
            $process->getExitCode(),
            $process->getOutput(),
            $process->getErrorOutput(),
            $target['display_name']
        );
    }

    /**
     * Reinicia el estado por escaneo.
     */
    protected function resetPerScanState(): void
    {
        $this->currentScanStartedAt = microtime(true);
        $this->currentLogContext = [];
        $this->currentStrictMode = false;
    }

    /**
     * Interpreta el código de salida del proceso.
     */
    protected function interpretExitCode(
        ?int $exitCode,
        string $stdout,
        string $stderr,
        string $displayName
    ): bool {
        if ($exitCode === null) {
            $this->logScan('error', sprintf('image_scan.%s_no_exit_code', $this->scannerKey()), [
                'tmp_path' => $displayName,
            ]);
            return $this->failOpen($this->currentStrictMode, AntivirusException::REASON_PROCESS_FAILED);
        }

        if ($this->isInfectedExitCode($exitCode)) {
            $sanitizedStdout = $this->sanitizeOutput($stdout);
            $this->logScan('warning', sprintf('image_scan.%s_detected', $this->scannerKey()), [
                'tmp_path'    => $displayName,
                'exit_code'   => $exitCode,
                'output_hash' => $sanitizedStdout['hash'],
            ]);
            return false;
        }

        if ($this->isErrorExitCode($exitCode)) {
            $sanitizedStdout = $this->sanitizeOutput($stdout);
            $sanitizedStderr = $this->sanitizeOutput($stderr);
            $this->logScan('error', sprintf('image_scan.%s_failed', $this->scannerKey()), [
                'tmp_path'     => $displayName,
                'exit_code'    => $exitCode,
                'output_hash'  => $sanitizedStdout['hash'],
                'stderr_hash'  => $sanitizedStderr['hash'],
            ]);
            return $this->failOpen($this->currentStrictMode, AntivirusException::REASON_PROCESS_FAILED);
        }

        return true;
    }

    /**
     * Determina si el código de salida indica infección.
     */
    protected function isInfectedExitCode(int $exitCode): bool
    {
        return $exitCode === 1;
    }

    /**
     * Determina si el código de salida indica error del escáner.
     */
    protected function isErrorExitCode(int $exitCode): bool
    {
        return $exitCode !== 0 && $exitCode !== 1;
    }

    /**
     * Posiciona el puntero del handle al inicio, si es seekable.
     */
    protected function ensureHandleSeekable($handle, string $displayName): bool
    {
        $rewindError = null;
        set_error_handler(static function (int $severity, string $message) use (&$rewindError): bool {
            $rewindError = $message;
            return true;
        }, E_WARNING);
        $rewound = rewind($handle);
        restore_error_handler();

        if ($rewound === false) {
            $this->logScan('error', sprintf('image_scan.%s_unseekable', $this->scannerKey()), [
                'target' => $displayName,
                'error'  => $this->sanitizePath($rewindError),
            ]);
            return false;
        }

        return true;
    }

    /* -------------------------------------------------------------------------
     |  Métodos abstractos (implementación específica del escáner)
     ------------------------------------------------------------------------- */

    abstract protected function scannerKey(): string;

    /**
     * @param array<string, mixed>|string|null $arguments
     * @param int $maxFileBytes
     * @return list<string>
     */
    abstract protected function sanitizeArguments(array|string|null $arguments, int $maxFileBytes): array;

    /**
     * @param string $binary
     * @param list<string> $arguments
     * @param array{handle: resource, path: string, display_name: string, size_bytes: int} $target
     * @param array<string, mixed> $scanConfig
     * @param array<string, mixed> $scannerConfig
     * @return array{
     *     command: list<string>,
     *     input?: resource|null,
     *     cleanup?: (callable():void)|null,
     *     uses_target_handle?: bool,
     *     close_target?: bool,
     *     fail_open_reason?: string
     * }|null
     */
    abstract protected function buildCommand(
        string $binary,
        array $arguments,
        array $target,
        array $scanConfig,
        array $scannerConfig,
    ): ?array;

    /* -------------------------------------------------------------------------
     |  Métodos comunes (pueden ser sobrescritos por clases hijas)
     ------------------------------------------------------------------------- */

    /**
     * Resuelve y valida el binario del escáner.
     */
    protected function resolveExecutable(array $scannerConfig): ?string
    {
        $binary = trim((string) ($scannerConfig['binary'] ?? ''));
        if ($binary === '') {
            $this->logScan('error', sprintf('image_scan.%s_binary_missing', $this->scannerKey()));
            return null;
        }

        $resolved = realpath($binary);
        if ($resolved === false) {
            $this->logScan('error', sprintf('image_scan.%s_binary_unavailable', $this->scannerKey()), [
                'binary_path' => $binary,
            ]);
            return null;
        }

        $normalizedBinary = $this->normalizePath($resolved);
        $allowlist = $this->allowedBinaries();

        if ($allowlist === [] || ! in_array($normalizedBinary, $allowlist, true)) {
            $this->logScan('error', sprintf('image_scan.%s_binary_not_allowlisted', $this->scannerKey()), [
                'binary_path' => $normalizedBinary,
            ]);
            return null;
        }

        if (! is_executable($resolved)) {
            $this->logScan('error', sprintf('image_scan.%s_binary_unavailable', $this->scannerKey()), [
                'binary_path' => $resolved,
            ]);
            return null;
        }

        return $resolved;
    }

    /**
     * Resuelve el archivo objetivo, abriendo un handle.
     *
     * @param array<string, mixed> $context
     * @param int $maxFileBytes
     * @return array{handle: resource, path: string, display_name: string, size_bytes: int}|null
     */
    protected function resolveTarget(array $context, int $maxFileBytes): ?array
    {
        $rawPath = $context['path'] ?? null;
        if (! is_string($rawPath) || $rawPath === '') {
            $this->logScan('error', sprintf('image_scan.%s_missing_path', $this->scannerKey()));
            return null;
        }

        // Prevenir path traversal simple
        if (str_contains(str_replace('\\', '/', $rawPath), '..')) {
            $this->logScan('error', sprintf('image_scan.%s_relative_path', $this->scannerKey()), [
                'path' => $rawPath,
            ]);
            return null;
        }

        $error = null;
        set_error_handler(static function (int $severity, string $message) use (&$error): bool {
            $error = $message;
            return true;
        });
        $handle = fopen($rawPath, 'rb');
        restore_error_handler();

        if ($handle === false) {
            $this->logScan('error', sprintf('image_scan.%s_open_failed', $this->scannerKey()), [
                'path'  => $rawPath,
                'error' => $this->sanitizePath($error),
            ]);
            return null;
        }

        // No permitir enlaces simbólicos
        $meta = stream_get_meta_data($handle);
        $uri = $meta['uri'] ?? null;
        if (is_string($uri) && is_link($uri)) {
            $this->closeHandle($handle);
            $this->logScan('error', sprintf('image_scan.%s_target_is_symlink', $this->scannerKey()), [
                'path' => $uri,
            ]);
            return null;
        }

        $realPath = is_string($uri) ? realpath($uri) : false;
        if ($realPath === false) {
            $this->closeHandle($handle);
            $this->logScan('error', sprintf('image_scan.%s_unreachable_path', $this->scannerKey()), [
                'path' => $rawPath,
            ]);
            return null;
        }

        $stat = fstat($handle);
        if ($stat === false || (($stat['mode'] ?? 0) & 0xF000) !== 0x8000) {
            $this->closeHandle($handle);
            $this->logScan('error', sprintf('image_scan.%s_not_regular_file', $this->scannerKey()), [
                'path' => $realPath,
            ]);
            return null;
        }

        // Verificar que esté dentro del directorio base permitido
        $allowedBase = $this->resolveAllowedBase();
        if ($allowedBase !== null) {
            $normalizedBase = $this->normalizePath($allowedBase);
            $normalizedReal = $this->normalizePath($realPath);
            $prefix = $normalizedBase === '/' ? '/' : $normalizedBase . '/';

            if ($normalizedReal !== $normalizedBase && ! str_starts_with($normalizedReal, $prefix)) {
                $this->closeHandle($handle);
                $this->logScan('error', sprintf('image_scan.%s_outside_base', $this->scannerKey()), [
                    'path'      => $realPath,
                    'base_path' => $normalizedBase,
                ]);
                return null;
            }
        }

        $size = $stat['size'] ?? null;
        if (! is_int($size)) {
            $this->closeHandle($handle);
            $this->logScan('error', sprintf('image_scan.%s_cannot_determine_size', $this->scannerKey()), [
                'path' => $realPath,
            ]);
            return null;
        }

        if ($maxFileBytes > 0 && $size > $maxFileBytes) {
            $this->closeHandle($handle);
            $this->logScan('warning', sprintf('image_scan.%s_file_too_large', $this->scannerKey()), [
                'path'       => $realPath,
                'size_bytes' => $size,
                'max_bytes'  => $maxFileBytes,
            ]);
            return null;
        }

        return [
            'handle'       => $handle,
            'path'         => $realPath,
            'display_name' => basename($realPath),
            'size_bytes'   => $size,
        ];
    }

    /**
     * Lista blanca de binarios permitidos (rutas normalizadas).
     * Puede ser sobrescrito para obtener la lista desde otra fuente.
     *
     * @return list<string>
     */
    protected function allowedBinaries(): array
    {
        return (array) ($this->globalConfig['bin_allowlist'] ?? []);
    }

    /**
     * Normaliza una ruta para comparación segura.
     */
    protected function normalizePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;
        $normalized = preg_replace('#/\./#', '/', $normalized) ?? $normalized;

        if (DIRECTORY_SEPARATOR === '\\') {
            $normalized = strtolower($normalized);
        }

        return rtrim($normalized, '/') ?: '/';
    }

    /**
     * Resuelve el directorio base permitido para archivos a escanear.
     */
    protected function resolveAllowedBase(): ?string
    {
        $base = $this->globalConfig['allowed_base_path'] ?? null;
        if (! is_string($base) || $base === '') {
            return null;
        }

        if (! is_dir($base) || is_link($base)) {
            $this->logScan('error', sprintf('image_scan.%s_base_invalid', $this->scannerKey()), [
                'base_path' => $this->normalizePath($base),
            ]);
            return null;
        }

        $realBase = realpath($base);
        if ($realBase === false) {
            return null;
        }

        return rtrim($realBase, DIRECTORY_SEPARATOR);
    }

    /**
     * Resuelve el tamaño máximo de archivo permitido.
     */
    protected function resolveMaxFileBytes(): int
    {
        $candidate = $this->scannerConfig['max_file_size'] ?? $this->globalConfig['max_file_size_bytes'] ?? 0;

        $value = filter_var(
            $candidate,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX]]
        );

        return $value === false ? 0 : (int) $value;
    }

    /**
     * Sanitiza la salida del proceso para logs.
     *
     * @return array{preview: string, hash: string|null}
     */
    protected function sanitizeOutput(?string $output): array
    {
        if ($output === null || $output === '') {
            return ['preview' => '', 'hash' => null];
        }

        $truncated = $this->safeSubstr($output, 0, self::OUTPUT_TRUNCATE_SIZE);
        $converted = $this->convertToUtf8($truncated);
        $clean = preg_replace($this->getPathPattern(), '[PATH]', $converted, limit: 50) ?? '';

        $hash = $clean === '' ? null : hash('sha1', $clean);
        $debug = (bool) ($this->globalConfig['debug'] ?? false);

        if (! $debug) {
            return ['preview' => '', 'hash' => $hash];
        }

        $preview = $this->safeSubstr($clean, 0, self::PREVIEW_TRUNCATE_SIZE);
        return ['preview' => $preview, 'hash' => $hash];
    }

    /**
     * Convierte a UTF-8 de forma segura.
     */
    private function convertToUtf8(string $string): string
    {
        if (function_exists('iconv')) {
            return iconv('UTF-8', 'UTF-8//IGNORE', $string) ?: '';
        }
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($string, 'UTF-8', 'UTF-8') ?: '';
        }
        return $string;
    }

    /**
     * Versión segura de substr.
     */
    private function safeSubstr(string $string, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($string, $start, $length, 'UTF-8') ?: '';
        }
        return substr($string, $start, $length) ?: '';
    }

    /**
     * Expresión regular para detectar rutas de archivo.
     */
    private function getPathPattern(): string
    {
        return '#(?:[A-Za-z]:)?(?:[/\\\\][^\s/\\\\]+)+[/\\\\]?#';
    }

    /**
     * Sanitiza un mensaje de error eliminando rutas del sistema.
     */
    protected function sanitizePath(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }
        return preg_replace($this->getPathPattern(), '[PATH]', $message);
    }

    /**
     * Decide entre permitir (fail-open) o denegar (fail-closed).
     *
     * @throws AntivirusException
     */
    protected function failOpen(bool $strictMode, string $reason): bool
    {
        $context = $this->baseLogContext([
            'reason' => $reason,
            'result' => $strictMode ? 'fail_closed' : 'fail_open',
        ]);

        if ($strictMode) {
            $this->securityLogger->critical(sprintf('image_scan.%s_fail_closed', $this->scannerKey()), $context);
            throw new AntivirusException($this->scannerKey(), $reason);
        }

        $this->logScan('warning', sprintf('image_scan.%s_fail_open', $this->scannerKey()), [
            'reason' => $reason,
            'result' => 'fail_open',
        ]);

        return true;
    }

    /**
     * Ejecuta un callback de limpieza atrapando excepciones.
     */
    protected function safelyInvokeCleanup(?callable $cleanup): void
    {
        if ($cleanup === null) {
            return;
        }

        try {
            $cleanup();
        } catch (Throwable $exception) {
            $this->logScan('debug', sprintf('image_scan.%s_cleanup_failed', $this->scannerKey()), [
                'error' => $this->sanitizePath($exception->getMessage()),
            ]);
        }
    }

    /**
     * Cierra un recurso de forma segura.
     */
    protected function closeHandle(mixed $handle): void
    {
        if (is_resource($handle)) {
            fclose($handle);
        }
    }

    /* -------------------------------------------------------------------------
     |  Métodos para manejo de reglas (YARA, etc.)
     ------------------------------------------------------------------------- */

    /**
     * Valida y copia un archivo de reglas a un temporal seguro.
     *
     * @param array<string, mixed> $scannerConfig
     * @param string|null $allowedBase
     * @return string|null
     */
    protected function resolveRulesPath(array $scannerConfig, ?string $allowedBase): ?string
    {
        $rulesPath = trim((string) ($scannerConfig['rules_path'] ?? ''));
        if ($rulesPath === '') {
            $this->logScan('error', sprintf('image_scan.%s_rules_missing', $this->scannerKey()));
            return null;
        }

        $realRules = realpath($rulesPath);
        if ($realRules === false) {
            $this->logScan('error', sprintf('image_scan.%s_rules_unreachable', $this->scannerKey()), [
                'rules_path' => $rulesPath,
            ]);
            return null;
        }

        $ext = strtolower(pathinfo($realRules, PATHINFO_EXTENSION));
        if (! is_file($realRules) || is_link($realRules) || ! in_array($ext, ['yar', 'yara', 'yarac'], true)) {
            $this->logScan('error', sprintf('image_scan.%s_rules_not_regular', $this->scannerKey()), [
                'rules_path' => $realRules,
            ]);
            return null;
        }

        if ($allowedBase !== null) {
            $normalizedBase = $this->normalizePath($allowedBase);
            $normalizedRules = $this->normalizePath($realRules);
            $prefix = $normalizedBase === '/' ? '/' : $normalizedBase . '/';

            if ($normalizedRules !== $normalizedBase && ! str_starts_with($normalizedRules, $prefix)) {
                $this->logScan('error', sprintf('image_scan.%s_rules_outside_allowed', $this->scannerKey()), [
                    'rules_path'  => $realRules,
                    'allowed_dir' => $normalizedBase,
                ]);
                return null;
            }
        }

        $handle = fopen($realRules, 'rb');
        if ($handle === false) {
            $this->logScan('error', sprintf('image_scan.%s_rules_open_failed', $this->scannerKey()), [
                'rules_path' => $realRules,
            ]);
            return null;
        }

        $tempPath = $this->copyStreamToTemp($handle, $this->scannerKey() . '_rules_');
        $this->closeHandle($handle);

        if ($tempPath === null) {
            $this->logScan('error', sprintf('image_scan.%s_rules_copy_failed', $this->scannerKey()), [
                'rules_path' => $realRules,
            ]);
        }

        return $tempPath;
    }

    /**
     * Copia un stream a un archivo temporal con nombre exclusivo, permisos seguros
     * y límite de tiempo/bytes.
     *
     * @param resource $input
     * @param string $prefix
     * @param int|null $maxBytes Límite máximo de bytes a copiar (null = sin límite).
     * @param int $timeoutSec Timeout absoluto en segundos.
     * @return string|null Ruta del archivo temporal.
     */
    protected function copyStreamToTemp(
        $input,
        string $prefix = self::DEFAULT_TEMP_PREFIX,
        ?int $maxBytes = null,
        int $timeoutSec = self::STREAM_COPY_TIMEOUT
    ): ?string {
        if (! is_resource($input)) {
            return null;
        }

        $directory = rtrim((string) sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        if ($directory === '' || ! is_writable($directory)) {
            $this->logScan('error', sprintf('image_scan.%s_temp_dir_invalid', $this->scannerKey()));
            return null;
        }

        // Validar que el directorio temporal sea seguro (ej. dentro de /tmp)
        $realDir = realpath($directory);
        if ($realDir === false || ! str_starts_with($realDir, '/tmp')) {
            $this->logScan('error', sprintf('image_scan.%s_temp_dir_unsafe', $this->scannerKey()), [
                'dir' => $directory,
            ]);
            return null;
        }

        $handle = null;
        $path = null;
        $lastError = null;

        for ($attempt = 0; $attempt < self::TEMP_FILE_MAX_ATTEMPTS; $attempt++) {
            try {
                $name = $prefix . bin2hex(random_bytes(16));
            } catch (Throwable $e) {
                $this->logScan('error', sprintf('image_scan.%s_temp_random_failed', $this->scannerKey()), [
                    'error' => $this->sanitizePath($e->getMessage()),
                ]);
                return null;
            }

            $candidate = $realDir . DIRECTORY_SEPARATOR . $name;

            // Crear con permisos 0600 desde el inicio
            $oldUmask = umask(0077);
            set_error_handler(static fn(int $severity, string $message) => $lastError = $message);
            $handle = fopen($candidate, 'xb');
            restore_error_handler();
            umask($oldUmask);

            if ($handle !== false) {
                $path = $candidate;
                break;
            }
        }

        if (! is_resource($handle) || $path === null) {
            $this->logScan('error', sprintf('image_scan.%s_temp_open_failed', $this->scannerKey()), [
                'error' => $this->sanitizePath($lastError ?? 'unknown error'),
            ]);
            return null;
        }

        // Copia con límites de bytes y timeout
        $copySucceeded = false;
        $startTime = time();

        try {
            $copySucceeded = $this->copyStreamWithLimit($input, $handle, $maxBytes, $startTime, $timeoutSec);
        } finally {
            if (fclose($handle) === false) {
                $this->logScan('error', sprintf('image_scan.%s_temp_close_failed', $this->scannerKey()));
                $copySucceeded = false;
            }
            if (! $copySucceeded && $path !== null) {
                @unlink($path);
            }
        }

        if (! $copySucceeded) {
            return null;
        }

        // No es necesario chmod porque el umask ya fijó 0600
        return $path;
    }

    /**
     * Copia un stream con límite de bytes, timeout absoluto y control de lecturas vacías.
     *
     * @param resource $source
     * @param resource $dest
     * @param int|null $maxBytes
     * @param int $startTime
     * @param int $timeoutSec
     * @return bool
     */
    private function copyStreamWithLimit(
        $source,
        $dest,
        ?int $maxBytes,
        int $startTime,
        int $timeoutSec
    ): bool {
        $bytesCopied = 0;
        $emptyReads = 0;

        stream_set_timeout($source, 5); // timeout por operación

        while (! feof($source)) {
            if (time() - $startTime > $timeoutSec) {
                $this->logScan('error', sprintf('image_scan.%s_temp_copy_timeout', $this->scannerKey()), [
                    'timeout_sec' => $timeoutSec,
                ]);
                return false;
            }

            $chunk = fread($source, self::STREAM_CHUNK_SIZE);
            if ($chunk === false) {
                $this->logScan('error', sprintf('image_scan.%s_temp_read_failed', $this->scannerKey()));
                return false;
            }

            if ($chunk === '') {
                $emptyReads++;
                $meta = stream_get_meta_data($source);
                if ($meta['timed_out'] ?? false) {
                    $this->logScan('warning', sprintf('image_scan.%s_temp_source_timeout', $this->scannerKey()));
                    return false;
                }

                if ($emptyReads >= self::MAX_EMPTY_READS) {
                    $this->logScan('warning', sprintf('image_scan.%s_temp_empty_reads_limit', $this->scannerKey()), [
                        'attempts' => $emptyReads,
                    ]);
                    return false;
                }

                usleep(1000);
                continue;
            }

            $emptyReads = 0;
            $chunkLength = strlen($chunk);

            if ($maxBytes !== null && ($bytesCopied + $chunkLength) > $maxBytes) {
                $this->logScan('warning', sprintf('image_scan.%s_temp_size_exceeded', $this->scannerKey()), [
                    'limit'  => $maxBytes,
                    'copied' => $bytesCopied,
                ]);
                return false;
            }

            $written = fwrite($dest, $chunk);
            if ($written === false || $written !== $chunkLength) {
                $this->logScan('error', sprintf('image_scan.%s_temp_write_failed', $this->scannerKey()));
                return false;
            }

            $bytesCopied += $written;
        }

        return true;
    }

    /**
     * Resuelve el directorio base permitido para archivos de reglas.
     */
    protected function resolveAllowedRulesBase(): ?string
    {
        $base = $this->globalConfig['allowed_rules_base_path'] ?? null;
        if (! is_string($base) || $base === '') {
            return null;
        }

        if (! is_dir($base) || is_link($base)) {
            $this->logScan('error', sprintf('image_scan.%s_rules_base_invalid', $this->scannerKey()), [
                'base_path' => $this->normalizePath($base),
            ]);
            return null;
        }

        $realBase = realpath($base);
        if ($realBase === false) {
            return null;
        }

        return rtrim($realBase, DIRECTORY_SEPARATOR);
    }

    /* -------------------------------------------------------------------------
     |  Logging y contexto
     ------------------------------------------------------------------------- */

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    protected function extractLogContext(array $context): array
    {
        return array_filter([
            'upload_id'      => $context['upload_id'] ?? null,
            'tenant_id'      => $context['tenant_id'] ?? null,
            'correlation_id' => $context['correlation_id'] ?? ($context['request_id'] ?? null),
            'user_id'        => $context['user_id'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    protected function baseLogContext(array $context = []): array
    {
        if ($this->currentScanStartedAt !== null && ! array_key_exists('duration_ms', $context)) {
            $context['duration_ms'] = (int) round((microtime(true) - $this->currentScanStartedAt) * 1000);
        }

        return array_merge(
            ['scanner_name' => $this->scannerKey()],
            $this->currentLogContext,
            $context
        );
    }

    /**
     * @param string $level (debug, info, warning, error, critical)
     * @param string $event
     * @param array<string, mixed> $context
     */
    protected function logScan(string $level, string $event, array $context = []): void
    {
        $payload = $this->baseLogContext($context);
        $logger = $this->securityLogger;

        match ($level) {
            'debug'    => $logger->debug($event, $payload),
            'info'     => $logger->info($event, $payload),
            'warning'  => $logger->warning($event, $payload),
            'error'    => $logger->error($event, $payload),
            'critical' => $logger->critical($event, $payload),
            default    => $logger->error($event, $payload),
        };
    }
}
