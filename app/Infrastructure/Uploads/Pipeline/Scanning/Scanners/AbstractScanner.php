<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Scanning\Scanners;

use App\Support\Logging\SecurityLogger;
use App\Infrastructure\Security\Exceptions\AntivirusException;
use App\Infrastructure\Uploads\Pipeline\Security\Logging\MediaSecurityLogger;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Plantilla base abstracta para escáneres de seguridad de archivos.
 *
 * Esta clase encapsula la lógica común y las medidas de endurecimiento
 * necesarias para interactuar de forma segura con binarios de escaneo externos
 * (como ClamAV, YARA, etc.). Proporciona funcionalidades como la validación
 * de configuración, la resolución y verificación de rutas de archivos y binarios,
 * la sanitización de argumentos, la gestión de recursos (handles), y el manejo
 * seguro de logs y errores.
 *
 * Las clases concretas que extiendan esta deben implementar los métodos
 * abstractos `sanitizeArguments` y `buildCommand` para adaptarse al escáner específico.
 */
abstract class AbstractScanner
{
    private ?MediaSecurityLogger $securityLogger = null;

    /**
     * @var array<string,mixed>
     */
    protected array $currentLogContext = [];
    protected ?float $currentScanStartedAt = null;

    /**
     * Indica si el modo estricto está activo para la ejecución actual del escáner.
     */
    protected bool $currentStrictMode = false;

    /**
     * Constructor del escáner.
     *
     * @param array<string, mixed>|null $config Configuración específica para esta instancia del escáner.
     *                                          Se fusionará con la configuración global.
     */
    public function __construct(protected readonly ?array $config = null)
    {
    }

    /**
     * Método invocable que ejecuta el escaneo de un archivo subido.
     *
     * Este método implementa la lógica general del escaneo, delegando
     * en los métodos abstractos para la construcción del comando específico
     * del escáner. Incluye validaciones, manejo de recursos y logging.
     *
     * @param UploadedFile $file El archivo subido a escanear.
     * @param array<string, mixed> $context Contexto adicional, incluyendo la ruta temporal del archivo
     *                                      y si es el primer fragmento.
     * @return bool `true` si el archivo es limpio, `false` si se detecta un virus, si hay un error
     *              o si la política de "fail-open" no lo permite.
     */
    final public function __invoke(UploadedFile $file, array $context): bool
    {
        // Solo escanea en el primer fragmento si se usa streaming.
        if (!($context['is_first_chunk'] ?? false)) {
            return true;
        }

        // Fusiona la configuración global con la específica del escáner y la instancia.
        $scanConfig = (array) config('image-pipeline.scan', []);
        $scannerConfig = array_merge((array) ($scanConfig[$this->scannerKey()] ?? []), $this->config ?? []);
        $this->currentStrictMode = (bool) ($scanConfig['strict'] ?? true);
        $strictMode = $this->currentStrictMode;
        $this->currentScanStartedAt = microtime(true);
        $this->currentLogContext = $this->extractLogContext($context);

        // Valida y resuelve el binario del escáner.
        $binary = $this->resolveExecutable($scannerConfig);
        if ($binary === null) {
            return $this->failOpen($strictMode, 'binary_missing');
        }

        // Resuelve el tamaño máximo permitido para el archivo.
        $maxFileBytes = $this->resolveMaxFileBytes($scanConfig, $scannerConfig);

        // Valida y resuelve el archivo objetivo para el escaneo.
        $target = $this->resolveTarget($context, $scanConfig, $maxFileBytes);
        if ($target === null) {
            return false;
        }

        // Sanitiza los argumentos para el comando del escáner.
        $arguments = $this->sanitizeArguments($scannerConfig['arguments'] ?? null, $maxFileBytes);

        // Construye el comando específico del escáner.
        $build = $this->buildCommand($binary, $arguments, $target, $scanConfig, $scannerConfig);
        if ($build === null) {
            $this->closeHandle($target['handle'] ?? null);
            return $this->failOpen($strictMode, 'build_failed');
        }

        // Maneja casos especiales devueltos por buildCommand.
        if (isset($build['fail_open_reason'])) {
            $result = $this->failOpen($this->strictMode(), (string) $build['fail_open_reason']);

            if (! ($build['target_closed'] ?? false)) {
                $this->closeHandle($target['handle'] ?? null);
            }

            if (isset($build['cleanup']) && is_callable($build['cleanup'])) {
                $this->safelyInvokeCleanup($build['cleanup']);
            }

            return $result;
        }

        // Verifica si el comando es válido.
        if (empty($build['command'])) {
            if (!($build['target_closed'] ?? false)) {
                $this->closeHandle($target['handle'] ?? null);
            }

            if (isset($build['cleanup']) && is_callable($build['cleanup'])) {
                $this->safelyInvokeCleanup($build['cleanup']);
            }

            return false;
        }

        $command = $build['command'];
        $inputHandle = $build['input'] ?? null;
        $usesTargetHandle = (bool) ($build['uses_target_handle'] ?? false);

        // Cierra el handle del archivo objetivo si no es usado directamente por el proceso.
        if (! $usesTargetHandle) {
            $this->closeHandle($target['handle'] ?? null);
        }

        // Ejecuta el proceso del escáner.
        $process = new Process($command);
        $timeout = (float) ($scannerConfig['timeout'] ?? $scanConfig['timeout'] ?? 10);
        $idleTimeout = (float) ($scanConfig['idle_timeout'] ?? $timeout);

        if ($timeout > 0) {
            $process->setTimeout($timeout);
        }
        if ($idleTimeout > 0) {
            $process->setIdleTimeout($idleTimeout);
        }

        try {
            if (is_resource($inputHandle)) {
                rewind($inputHandle);
                $process->setInput($inputHandle);
            }

            $process->run();
        } catch (ProcessTimedOutException $exception) {
            $this->logScan('error',
                sprintf('image_scan.%s_timeout', $this->scannerKey()),
                [
                    'tmp_path' => $target['display_name'],
                    'result' => 'scan_failed',
                    'error' => $exception->getMessage(),
                ]
            );

            return $this->failOpen($strictMode, 'process_timeout');
        } catch (Throwable $exception) {
            $this->logScan('error',
                sprintf('image_scan.%s_exception', $this->scannerKey()),
                [
                    'tmp_path' => $target['display_name'],
                    'result' => 'scan_failed',
                    'error' => $exception->getMessage(),
                ]
            );

            return $this->failOpen($strictMode, 'process_exception');
        } finally {
            // Cierra handles y ejecuta limpieza en el bloque finally.
            if (! $usesTargetHandle) {
                $this->closeHandle($inputHandle);
            }

            if (isset($build['cleanup']) && is_callable($build['cleanup'])) {
                $this->safelyInvokeCleanup($build['cleanup']);
            }
        }

        // Evalúa el resultado del proceso.
        $exitCode = $process->getExitCode();
        if ($exitCode === 0) {
            return true; // Archivo limpio.
        }

        $sanitizedStdout = $this->sanitizeOutput($process->getOutput());
        $sanitizedStderr = $this->sanitizeOutput($process->getErrorOutput());

        if ($exitCode === 1) {
            // Virus o malware detectado.
            $this->logScan('warning',
                sprintf('image_scan.%s_detected', $this->scannerKey()),
                [
                    'tmp_path'     => $target['display_name'],
                    'size_bytes'   => $target['size_bytes'],
                    'result'       => 'infected',
                    'exit_code'    => $exitCode,
                    'output_hash'  => $sanitizedStdout['hash'],
                ]
            );

            return false;
        }

        // Otro error (código de salida != 0 y != 1).
        $this->logScan('error',
            sprintf('image_scan.%s_failed', $this->scannerKey()),
            [
                'tmp_path'     => $target['display_name'],
                'result'       => 'scan_failed',
                'exit_code'    => $exitCode,
                'output_hash'  => $sanitizedStdout['hash'],
                'stderr_hash'  => $sanitizedStderr['hash'],
            ]
        );

        return $this->failOpen($strictMode, 'process_failed');
    }

    /**
     * Obtiene la clave identificadora única del escáner.
     *
     * Esta clave se utiliza para generar nombres de logs y mensajes de error específicos.
     *
     * @return string Clave del escáner (por ejemplo, 'clamav', 'yara').
     */
    abstract protected function scannerKey(): string;

    /**
     * Obtiene el estado del modo estricto para la ejecución actual.
     *
     * @return bool `true` si el modo estricto está activo, `false` en caso contrario.
     */
    protected function strictMode(): bool
    {
        return $this->currentStrictMode;
    }

    /**
     * Sanitiza y filtra los argumentos para el comando del escáner específico.
     *
     * @param array<string, mixed>|string|null $arguments Argumentos sin procesar.
     * @param int $maxFileBytes Tamaño máximo del archivo, útil para validar argumentos.
     * @return list<string> Lista de argumentos sanitizados y seguros.
     */
    abstract protected function sanitizeArguments(array|string|null $arguments, int $maxFileBytes): array;

    /**
     * Construye el comando específico del escáner.
     *
     * @param string $binary Ruta al binario del escáner.
     * @param array<string, string> $arguments Argumentos sanitizados.
     * @param array<string, mixed> $target Información sobre el archivo objetivo (path, handle, etc.).
     * @param array<string, mixed> $scanConfig Configuración global del escaneo.
     * @param array<string, mixed> $scannerConfig Configuración específica del escáner.
     * @return array{command: list<string>, input: resource|null, cleanup: (callable|null), uses_target_handle?: bool, target_closed?: bool}|null
     *         Array con el comando, handle de entrada opcional, función de limpieza y flags de estado,
     *         o `null` si la construcción falla.
     */
    abstract protected function buildCommand(
        string $binary,
        array $arguments,
        array $target,
        array $scanConfig,
        array $scannerConfig,
    ): ?array;

    /**
     * Valida y resuelve la ruta del binario ejecutable del escáner.
     *
     * Verifica que exista, sea ejecutable y esté en la lista blanca de binarios permitidos.
     *
     * @param array<string, mixed> $scannerConfig Configuración del escáner.
     * @return string|null Ruta absoluta del binario si es válido, `null` en caso contrario.
     */
    protected function resolveExecutable(array $scannerConfig): ?string
    {
        $binary = trim((string) ($scannerConfig['binary'] ?? ''));
        if ($binary === '') {
            $this->logScan('error', sprintf('image_scan.%s_binary_missing', $this->scannerKey()));
            return null;
        }

        $resolved = realpath($binary);
        if ($resolved === false || $resolved === '') {
            $this->logScan('error', sprintf('image_scan.%s_binary_unavailable', $this->scannerKey()), ['binary_path' => $binary]);
            return null;
        }

        $normalizedBinary = $this->normalizePath($resolved);
        $allowlist = $this->allowedBinaries();

        if ($allowlist === [] || ! in_array($normalizedBinary, $allowlist, true)) {
            $this->logScan('error',
                sprintf('image_scan.%s_binary_not_allowlisted', $this->scannerKey()),
                ['binary_path' => $normalizedBinary]
            );
            return null;
        }

        if (! is_executable($resolved)) {
            $this->logScan('error',
                sprintf('image_scan.%s_binary_unavailable', $this->scannerKey()),
                ['binary_path' => $resolved]
            );
            return null;
        }

        return $resolved;
    }

    /**
     * Valida y resuelve la ruta del archivo objetivo para el escaneo, abriendo un handle.
     *
     * Verifica que la ruta exista, sea un archivo regular, esté dentro del directorio base permitido,
     * no sea un enlace simbólico y no exceda el tamaño máximo permitido.
     *
     * @param array<string, mixed> $context Contexto que contiene la ruta del archivo.
     * @param array<string, mixed> $scanConfig Configuración global del escaneo.
     * @param int $maxFileBytes Tamaño máximo permitido para el archivo.
     * @return array{handle: resource, path: string, display_name: string, size_bytes: int|null}|null
     *         Detalles del archivo y su handle si es válido, `null` en caso contrario.
     */
    protected function resolveTarget(array $context, array $scanConfig, int $maxFileBytes): ?array
    {
        $rawPath = $context['path'] ?? null;
        if (! is_string($rawPath) || $rawPath === '') {
            $this->logScan('error', sprintf('image_scan.%s_missing_path', $this->scannerKey()));
            return null;
        }

        $rawNormalized = str_replace('\\', '/', $rawPath);
        if (str_contains($rawNormalized, '..')) {
            $this->logScan('error', sprintf('image_scan.%s_relative_path', $this->scannerKey()), ['path' => $rawPath]);
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
            $this->logScan('error',
                sprintf('image_scan.%s_open_failed', $this->scannerKey()),
                ['path' => $rawPath, 'error' => $error]
            );
            return null;
        }

        $meta = stream_get_meta_data($handle);
        $uri = $meta['uri'] ?? null;
        if (is_string($uri) && is_link($uri)) {
            $this->closeHandle($handle);
            $this->logScan('error', sprintf('image_scan.%s_target_is_symlink', $this->scannerKey()), ['path' => $uri]);
            return null;
        }

        $realPath = is_string($uri) ? realpath($uri) : false;
        if ($realPath === false) {
            $this->closeHandle($handle);
            $this->logScan('error',
                sprintf('image_scan.%s_unreachable_path', $this->scannerKey()),
                ['path' => $rawPath]
            );
            return null;
        }

        $stat = fstat($handle);
        if ($stat === false || (($stat['mode'] ?? 0) & 0xF000) !== 0x8000) {
            $this->closeHandle($handle);
            $this->logScan('error',
                sprintf('image_scan.%s_not_regular_file', $this->scannerKey()),
                ['path' => $realPath]
            );
            return null;
        }

        $allowedBase = $this->resolveAllowedBase($scanConfig);
        if ($allowedBase !== null) {
            $normalizedBase = $this->normalizePath($allowedBase);
            $normalizedReal = $this->normalizePath($realPath);
            $prefix = $normalizedBase === '/' ? '/' : $normalizedBase . '/';

            if ($normalizedReal !== $normalizedBase && ! str_starts_with($normalizedReal, $prefix)) {
                $this->closeHandle($handle);
                $this->logScan('error',
                    sprintf('image_scan.%s_outside_base', $this->scannerKey()),
                    ['path' => $realPath, 'base_path' => $normalizedBase]
                );
                return null;
            }
        }

        $size = $stat['size'] ?? null;
        if (! is_int($size) && is_string($size) && ctype_digit($size)) {
            $size = (int) $size;
        }

        if ($maxFileBytes > 0 && is_int($size) && $size > $maxFileBytes) {
            $this->closeHandle($handle);
            $this->logScan('warning',
                sprintf('image_scan.%s_file_too_large', $this->scannerKey()),
                [
                    'path'       => $realPath,
                    'size_bytes' => $size,
                    'max_bytes'  => $maxFileBytes,
                ]
            );
            return null;
        }

        return [
            'handle'       => $handle,
            'path'         => $realPath,
            'display_name' => basename($realPath),
            'size_bytes'   => is_int($size) ? $size : null,
        ];
    }

    /**
     * Obtiene la lista blanca de binarios permitidos desde la configuración.
     *
     * @return list<string> Lista de rutas normalizadas de binarios permitidos.
     */
    protected function allowedBinaries(): array
    {
        $allowlist = config('image-pipeline.scan.bin_allowlist', []);
        if (! is_array($allowlist)) {
            return [];
        }

        $normalized = [];
        foreach ($allowlist as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            $resolved = realpath($candidate);
            if ($resolved === false || $resolved === '') {
                continue;
            }

            $normalized[] = $this->normalizePath($resolved);
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Normaliza una ruta de archivo para unificar formatos y eliminar referencias relativas.
     *
     * @param string $path Ruta a normalizar.
     * @return string Ruta normalizada.
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
     * Resuelve el directorio base permitido para escaneo.
     *
     * @param array<string, mixed> $scanConfig Configuración global del escaneo.
     * @return string|null Ruta absoluta del directorio base si es válido, `null` en caso contrario.
     */
    protected function resolveAllowedBase(array $scanConfig): ?string
    {
        $base = $scanConfig['allowed_base_path'] ?? null;
        if (! is_string($base) || $base === '') {
            return null;
        }

        if (! is_dir($base) || is_link($base)) {
            $this->logScan('error',
                sprintf('image_scan.%s_base_invalid', $this->scannerKey()),
                ['base_path' => $this->normalizePath($base)]
            );
            return null;
        }

        $realBase = realpath($base);
        if ($realBase === false || $realBase === '') {
            return null;
        }

        return rtrim($realBase, DIRECTORY_SEPARATOR);
    }

    /**
     * Resuelve el tamaño máximo permitido para archivos a escanear.
     *
     * @param array<string, mixed> $scanConfig Configuración global del escaneo.
     * @param array<string, mixed> $scannerConfig Configuración específica del escáner.
     * @return int Tamaño máximo en bytes, o 0 si no está definido o es inválido.
     */
    protected function resolveMaxFileBytes(array $scanConfig, array $scannerConfig): int
    {
        $candidate = $scannerConfig['max_file_size'] ?? $scanConfig['max_file_size_bytes'] ?? 0;

        $value = filter_var(
            $candidate,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX]]
        );

        return $value === false ? 0 : (int) $value;
    }

    /**
     * Sanitiza la salida (stdout/stderr) del proceso del escáner.
     *
     * @param string|null $output Salida del proceso.
     * @return array{preview: string, hash: string|null} Salida sanitizada y truncada, y su hash SHA1.
     */
    protected function sanitizeOutput(?string $output): array
    {
        if ($output === null || $output === '') {
            return ['preview' => '', 'hash' => null];
        }

        $truncated = mb_substr($output, 0, 1000);
        $converted = iconv('UTF-8', 'UTF-8//IGNORE', $truncated);
        if ($converted === false) {
            $converted = '';
        }

        $clean = preg_replace('#(?:[A-Za-z]:)?[/\\\\](?:[^\s/\\\\]+[/\\\\])*[^\s/\\\\]*#', '[PATH]', $converted) ?? '';
        $hash = $clean === '' ? null : hash('sha1', $clean);

        // En modo no debug, no se devuelve el contenido del preview para seguridad.
        if (! config('app.debug', false)) {
            return ['preview' => '', 'hash' => $hash];
        }

        $preview = mb_substr($clean, 0, 600);

        return [
            'preview' => $preview,
            'hash'    => $hash,
        ];
    }

    /**
     * Decide si permitir o denegar el archivo en caso de error de configuración o validación.
     *
     * @param bool $strictMode Indica si el modo estricto está activo.
     * @param string $reason Razón del fallo.
     * @return bool `true` si se permite (fail-open), `false` si se deniega (fail-closed).
     */
    protected function failOpen(bool $strictMode, string $reason): bool
    {
        if ($strictMode) {
            $this->securityLogger()->critical(sprintf('image_scan.%s_fail_closed', $this->scannerKey()), $this->baseLogContext([
                'reason' => $reason,
                'result' => 'scan_failed',
            ]));
            throw new AntivirusException($this->scannerKey(), $reason);
        }

        $this->logScan('warning', sprintf('image_scan.%s_fail_open', $this->scannerKey()), [
            'reason' => $reason,
            'result' => 'fail_open',
        ]);

        return true;
    }

    /**
     * Invoca una función de limpieza de forma segura, registrando errores si ocurren.
     *
     * @param callable(): void $cleanup Función de limpieza a ejecutar.
     * @return void
     */
    protected function safelyInvokeCleanup(callable $cleanup): void
    {
        try {
            $cleanup();
        } catch (Throwable $exception) {
            $this->logScan('debug',
                sprintf('image_scan.%s_cleanup_failed', $this->scannerKey()),
                ['error' => $exception->getMessage()]
            );
        }
    }

    /**
     * Cierra un handle de archivo de forma segura.
     *
     * @param mixed $handle Handle a cerrar.
     * @return void
     */
    protected function closeHandle(mixed $handle): void
    {
        if (is_resource($handle)) {
            fclose($handle);
        }
    }

    /**
     * Valida y copia un archivo de reglas a una ubicación temporal segura.
     *
     * @param array<string, mixed> $scannerConfig Configuración específica del escáner.
     * @param string|null $allowedBase Directorio base permitido para reglas.
     * @return string|null Ruta temporal del archivo de reglas copiado, o `null` si falla.
     */
    protected function resolveRulesPath(array $scannerConfig, ?string $allowedBase): ?string
    {
        $rulesPath = trim((string) ($scannerConfig['rules_path'] ?? ''));
        if ($rulesPath === '') {
            $this->logScan('error', sprintf('image_scan.%s_rules_missing', $this->scannerKey()));
            return null;
        }

        $realRules = realpath($rulesPath);
        if ($realRules === false || $realRules === '') {
            $this->logScan('error',
                sprintf('image_scan.%s_rules_unreachable', $this->scannerKey()),
                ['rules_path' => $rulesPath]
            );
            return null;
        }

        $ext = strtolower(pathinfo($realRules, PATHINFO_EXTENSION));
        if (! is_file($realRules) || is_link($realRules) || ! in_array($ext, ['yar', 'yara', 'yarac'], true)) {
            $this->logScan('error',
                sprintf('image_scan.%s_rules_not_regular', $this->scannerKey()),
                ['rules_path' => $realRules]
            );
            return null;
        }

        if ($allowedBase !== null) {
            $normalizedBase = $this->normalizePath($allowedBase);
            $normalizedRules = $this->normalizePath($realRules);
            $prefix = $normalizedBase === '/' ? '/' : $normalizedBase . '/';

            if ($normalizedRules !== $normalizedBase && ! str_starts_with($normalizedRules, $prefix)) {
                $this->logScan('error',
                    sprintf('image_scan.%s_rules_outside_allowed', $this->scannerKey()),
                    ['rules_path' => $realRules, 'allowed_dir' => $normalizedBase]
                );
                return null;
            }
        }

        $handle = fopen($realRules, 'rb');
        if ($handle === false) {
            $this->logScan('error',
                sprintf('image_scan.%s_rules_open_failed', $this->scannerKey()),
                ['rules_path' => $realRules]
            );
            return null;
        }

        $tempPath = $this->copyStreamToTemp($handle, 'yara_rules_');
        $this->closeHandle($handle);

        if ($tempPath === null) {
            $this->logScan('error',
                sprintf('image_scan.%s_rules_copy_failed', $this->scannerKey()),
                ['rules_path' => $realRules]
            );
            return null;
        }

        return $tempPath;
    }

    /**
     * Crea un archivo temporal seguro con el contenido de un stream.
     *
     * @param resource $input Stream de entrada a copiar.
     * @param string $prefix Prefijo para el nombre del archivo temporal.
     * @return string|null Ruta del archivo temporal creado, o `null` si falla.
     */
    protected function copyStreamToTemp($input, string $prefix): ?string
    {
        if (! is_resource($input)) {
            return null;
        }

        $directory = rtrim((string) sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        if ($directory === '') {
            return null;
        }

        $handle = null;
        $path = null;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $name = $prefix . bin2hex(random_bytes(16));
            } catch (Throwable $exception) {
                $this->logScan('error',
                    sprintf('image_scan.%s_temp_random_failed', $this->scannerKey()),
                    ['error' => $exception->getMessage()]
                );
                return null;
            }

            $candidate = $directory . DIRECTORY_SEPARATOR . $name;
            $handle = @fopen($candidate, 'xb');
            if ($handle !== false) {
                $path = $candidate;
                break;
            }
        }

        if (! is_resource($handle) || $path === null) {
            $this->logScan('error', sprintf('image_scan.%s_temp_open_failed', $this->scannerKey()));
            return null;
        }

        $copySucceeded = false;
        try {
            $copySucceeded = stream_copy_to_stream($input, $handle) !== false;
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

        if (! chmod($path, 0600)) { // Solo lectura/escritura para el propietario.
            unlink($path);
            return null;
        }

        return $path;
    }

    /**
     * Resuelve el directorio base permitido para archivos de reglas.
     *
     * @param array<string, mixed> $scanConfig Configuración global del escaneo.
     * @return string|null Ruta absoluta del directorio base si es válido, `null` en caso contrario.
     */
    protected function resolveAllowedRulesBase(array $scanConfig): ?string
    {
        $base = $scanConfig['allowed_rules_base_path'] ?? null;
        if (! is_string($base) || $base === '') {
            return null;
        }

        if (! is_dir($base) || is_link($base)) {
            $this->logScan('error',
                sprintf('image_scan.%s_rules_base_invalid', $this->scannerKey()),
                ['base_path' => $this->normalizePath($base)]
            );
            return null;
        }

        $realBase = realpath($base);
        if ($realBase === false || $realBase === '') {
            return null;
        }

        return rtrim($realBase, DIRECTORY_SEPARATOR);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    protected function extractLogContext(array $context): array
    {
        return array_filter([
            'upload_id' => $context['upload_id'] ?? null,
            'tenant_id' => $context['tenant_id'] ?? null,
            'correlation_id' => $context['correlation_id'] ?? ($context['request_id'] ?? null),
            'user_id' => $context['user_id'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
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
     * @param array<string,mixed> $context
     */
    protected function logScan(string $level, string $event, array $context = []): void
    {
        $payload = $this->baseLogContext($context);
        $logger = $this->securityLogger();

        match ($level) {
            'debug' => $logger->debug($event, $payload),
            'info' => $logger->info($event, $payload),
            'warning' => $logger->warning($event, $payload),
            'error' => $logger->error($event, $payload),
            'critical' => $logger->critical($event, $payload),
            default => $logger->error($event, $payload),
        };
    }

    protected function securityLogger(): MediaSecurityLogger
    {
        return $this->securityLogger ??= app(MediaSecurityLogger::class);
    }

}
