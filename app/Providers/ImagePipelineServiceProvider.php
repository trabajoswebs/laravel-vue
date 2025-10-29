<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\RateLimitUploads;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
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
            config(['image-pipeline.max_bytes' => 15 * 1024 * 1024]); // 15MB predeterminado
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
            Log::critical('image_pipeline.invalid_configuration', [
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
            Log::warning('image_pipeline.base_path_fallback', ['base' => $base, 'fallback' => $fallbackPath]);
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
            Log::warning('image_pipeline.rules_base_fallback', ['base' => $rulesBase, 'fallback' => $fallbackBase]);
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
        $allowlist = $scanConfig['bin_allowlist'] ?? [];
        if (! is_array($allowlist)) {
            $allowlist = [];
        }

        $normalized = [];
        $hadInput = $allowlist !== [];
        $hadInvalid = false;

        foreach ($allowlist as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            $resolved = realpath($candidate);
            if ($resolved === false || $resolved === '' || ! is_executable($resolved)) {
                $hadInvalid = true;
                continue;
            }

            $normalized[] = $this->normalizePath($resolved);
        }

        if ($hadInvalid) {
            Log::warning('image_pipeline.binary_allowlist_filtered', ['invalid' => true]);
        }

        // Aplica la lista normalizada y filtrada.
        config(['image-pipeline.scan.bin_allowlist' => array_values(array_unique($normalized))]);

        $strict = (bool) ($scanConfig['strict'] ?? false);
        if ($strict && $hadInput && $normalized === []) {
            $failures[] = 'bin_allowlist';
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
}
