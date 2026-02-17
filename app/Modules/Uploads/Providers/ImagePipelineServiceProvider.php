<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Providers;

use App\Support\Logging\SecurityLogger;
use App\Modules\Uploads\Middleware\RateLimitUploads;
use App\Modules\Uploads\Pipeline\Security\Logging\MediaLogSanitizer;
use App\Modules\Uploads\Pipeline\Security\Logging\MediaSecurityLogger;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Proveedor de servicios para la funcionalidad del pipeline de imágenes.
 *
 * Este proveedor se encarga de:
 * - Registrar alias de middleware relacionados con imágenes.
 * - Validar la configuración del pipeline (`config/image-pipeline.php`).
 * - Aplicar valores predeterminados y registrar logs si la configuración es inválida.
 */
final class ImagePipelineServiceProvider extends ServiceProvider
{
    private ?MediaSecurityLogger $securityLogger = null;
    private ?MediaLogSanitizer $logSanitizer = null;

    public function boot(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('rate.uploads', RateLimitUploads::class);

        $this->runConfigurationChecks();
    }

    /**
     * Valida la configuración del pipeline y aplica valores predeterminados si es necesario.
     *
     * Verifica que valores críticos como `max_bytes`, `max_megapixels`,
     * y rutas de escaneo sean válidos. Si hay errores, se registran o lanzan
     * dependiendo del entorno.
     *
     * @return void
     */
    private function runConfigurationChecks(): void
    {
        $config = config('image-pipeline');
        if (! is_array($config)) {
            throw new InvalidArgumentException('Configuration "image-pipeline" must be an array.');
        }

        $failures = [];

        // Valida y corrige max_bytes
        $maxBytes = (int) ($config['max_bytes'] ?? 0);
        if ($maxBytes <= 0) {
            $failures[] = 'max_bytes';
            config(['image-pipeline.max_bytes' => 25 * 1024 * 1024]); // 25MB predeterminado
        }

        // Valida y corrige bomb_ratio_threshold
        $bombRatio = (int) ($config['bomb_ratio_threshold'] ?? 100);
        if ($bombRatio <= 0) {
            $failures[] = 'bomb_ratio_threshold';
            config(['image-pipeline.bomb_ratio_threshold' => 100]);
        }

        // Valida y corrige max_megapixels
        $maxMegapixels = (float) ($config['max_megapixels'] ?? 0);
        if ($maxMegapixels <= 0) {
            $failures[] = 'max_megapixels';
            config(['image-pipeline.max_megapixels' => 48.0]);
        }

        // Valida configuraciones específicas del escaneo de seguridad
        $scanConfig = (array) ($config['scan'] ?? []);
        $this->validateAllowedBasePath($scanConfig, $failures);
        $this->validateAllowedRulesBasePath($scanConfig, $failures);
        $this->validateAllowlistedBinaries($scanConfig, $failures);

        // Maneja los fallos detectados
        if ($failures !== []) {
            if (app()->environment(['local', 'testing'])) {
                // En entornos locales o de prueba, lanza una excepción para forzar la corrección.
                throw new InvalidArgumentException(
                    'Invalid image pipeline configuration: ' . implode(', ', array_unique($failures))
                );
            }

            // En producción, registra un log crítico y aplica valores seguros.
            $this->securityLogger()->critical('image_pipeline.invalid_configuration', [
                'issues' => array_unique($failures),
            ]);
        }
    }

    /**
     * Valida la ruta base permitida para escaneo de archivos.
     *
     * Verifica que la ruta sea un directorio real y no un enlace simbólico.
     * Si no es válida, se aplica un valor predeterminado seguro.
     *
     * @param array<string, mixed> $scanConfig Configuración del escaneo.
     * @param list<string> &$failures Lista de errores encontrados (se pasa por referencia).
     * @return void
     */
    private function validateAllowedBasePath(array $scanConfig, array &$failures): void
    {
        $base = $scanConfig['allowed_base_path'] ?? null;
        if (! is_string($base) || $base === '') {
            return;
        }

        if (! is_dir($base) || is_link($base)) {
            $fallbackPath = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
            if (! is_dir($fallbackPath)) {
                $failures[] = 'allowed_base_path';
                return;
            }

            config(['image-pipeline.scan.allowed_base_path' => rtrim($fallbackPath, DIRECTORY_SEPARATOR)]);
            $this->securityLogger()->debug('media.pipeline.boot', [
                'event' => 'image_pipeline.base_path_fallback',
                'base_path' => $base,
                'fallback_path' => $fallbackPath,
            ]);
        }
    }

    /**
     * Valida la ruta base permitida para reglas de escaneo (como YARA).
     *
     * Verifica que la ruta sea un directorio real y no un enlace simbólico.
     * Si no es válida, se aplica un valor predeterminado seguro.
     *
     * @param array<string, mixed> $scanConfig Configuración del escaneo.
     * @param list<string> &$failures Lista de errores encontrados (se pasa por referencia).
     * @return void
     */
    private function validateAllowedRulesBasePath(array $scanConfig, array &$failures): void
    {
        $rulesBase = $scanConfig['allowed_rules_base_path'] ?? null;
        if (! is_string($rulesBase) || $rulesBase === '') {
            return;
        }

        if (! is_dir($rulesBase) || is_link($rulesBase)) {
            $fallbackBase = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
            if (! is_dir($fallbackBase)) {
                $failures[] = 'allowed_rules_base_path';
                return;
            }

            config(['image-pipeline.scan.allowed_rules_base_path' => rtrim($fallbackBase, DIRECTORY_SEPARATOR)]);
            $this->securityLogger()->debug('media.pipeline.boot', [
                'event' => 'image_pipeline.rules_base_fallback',
                'base_path' => $rulesBase,
                'fallback_path' => $fallbackBase,
            ]);
        }
    }

    /**
     * Valida la lista blanca de binarios ejecutables para escaneo.
     *
     * Verifica que cada binario en la lista exista, sea un archivo regular
     * y sea ejecutable. Los binarios inválidos se excluyen de la lista.
     *
     * @param array<string, mixed> $scanConfig Configuración del escaneo.
     * @param list<string> &$failures Lista de errores encontrados (se pasa por referencia).
     * @return void
     */
    private function validateAllowlistedBinaries(array $scanConfig, array &$failures): void
    {
        static $loggedInvalid = false;

        $allowlist = $scanConfig['bin_allowlist'] ?? [];
        if (! is_array($allowlist)) {
            $allowlist = [];
        }

        // Si no hay entradas configuradas, no hacemos nada y evitamos warnings.
        if ($allowlist === []) {
            config(['image-pipeline.scan.bin_allowlist' => []]);
            return;
        }

        $normalized = [];
        $invalidEntries = [];
        $hadInput = false;

        foreach ($allowlist as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $trimmed = trim($candidate);
            if ($trimmed === '') {
                continue;
            }

            $hadInput = true;

            $resolved = realpath($trimmed);
            if ($resolved === false || $resolved === '') {
                $invalidEntries[] = [
                    'binary' => $trimmed,
                    'reason' => 'missing',
                ];
                continue;
            }

            if (! is_executable($resolved)) {
                $invalidEntries[] = [
                    'binary' => $this->normalizePath($resolved),
                    'reason' => 'not_executable',
                ];
                continue;
            }

            $normalized[] = $this->normalizePath($resolved);
        }

        $normalized = array_values(array_unique($normalized));
        $customAllowlist = (bool) ($scanConfig['bin_allowlist_from_env'] ?? false);
        $strict = (bool) ($scanConfig['strict'] ?? false);

        if ($normalized === []) {
            // Si toda la allowlist configurada es inválida, intenta un fallback seguro.
            $fallbackCandidates = [
                '/usr/bin/clamdscan',
                '/usr/local/bin/clamdscan',
                '/usr/bin/clamscan',
                '/usr/local/bin/clamscan',
            ];

            foreach ($fallbackCandidates as $fallback) {
                $real = realpath($fallback);
                if ($real !== false && $real !== '' && is_executable($real)) {
                    $normalized[] = $this->normalizePath($real);
                }
            }

            $normalized = array_values(array_unique($normalized));

            if ($normalized === []) {
                config(['image-pipeline.scan.bin_allowlist' => []]);

                // En local/testing silenciamos para evitar spam; simplemente deshabilita el escáner.
                if (app()->environment(['local', 'testing'])) {
                    return;
                }

                if ($strict && $hadInput) {
                    $failures[] = 'bin_allowlist';
                }

                if ($hadInput && ! $loggedInvalid) {
                    $this->securityLogger()->warning('media.pipeline.boot', [
                        'event' => 'image_pipeline.binary_allowlist_invalid',
                        'custom' => $customAllowlist,
                        'candidates' => $this->hashBinaryEntries($invalidEntries),
                    ]);
                    $loggedInvalid = true;
                }

                return;
            }

            $this->securityLogger()->debug('media.pipeline.boot', [
                'event' => 'image_pipeline.binary_allowlist_fallback',
                'used_fallback_hashes' => array_map(
                    fn (string $path): string => $this->logSanitizer()->hashPath($path),
                    $normalized
                ),
                'custom' => $customAllowlist,
                'discarded' => $this->hashBinaryEntries($invalidEntries),
            ]);
        }

        // Aplica la lista normalizada y filtrada.
        config(['image-pipeline.scan.bin_allowlist' => $normalized]);

        if ($invalidEntries !== []) {
            $payload = [
                'event' => 'image_pipeline.binary_allowlist_filtered',
                'discarded_total' => count($invalidEntries),
                'filtered' => array_slice($this->hashBinaryEntries($invalidEntries), 0, 5),
            ];

            if ($customAllowlist) {
                $this->securityLogger()->warning('media.pipeline.boot', $payload);
            } else {
                $this->securityLogger()->debug('media.pipeline.boot', $payload);
            }
        }
    }

    /**
     * Normaliza una ruta de archivo para unificar formatos y eliminar referencias relativas.
     *
     * @param string $path Ruta a normalizar.
     * @return string Ruta normalizada.
     */
    private function normalizePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;
        $normalized = preg_replace('#/\./#', '/', $normalized) ?? $normalized;

        return rtrim($normalized, '/') ?: '/';
    }

    /**
     * @param list<array{binary: string, reason: string}> $entries
     * @return list<array{binary_hash: string, reason: string}>
     */
    private function hashBinaryEntries(array $entries): array
    {
        $hashed = [];

        foreach ($entries as $entry) {
            $hashed[] = [
                'binary_hash' => $this->logSanitizer()->hashPath((string) ($entry['binary'] ?? '')),
                'reason' => (string) ($entry['reason'] ?? 'unknown'),
            ];
        }

        return $hashed;
    }

    private function securityLogger(): MediaSecurityLogger
    {
        return $this->securityLogger ??= $this->app->make(MediaSecurityLogger::class);
    }

    private function logSanitizer(): MediaLogSanitizer
    {
        return $this->logSanitizer ??= $this->app->make(MediaLogSanitizer::class);
    }
}
