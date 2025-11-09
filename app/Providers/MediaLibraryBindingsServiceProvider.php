<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;

/**
 * Proveedor de servicios para la biblioteca Spatie Media Library.
 *
 * Este proveedor se encarga de enlazar la interfaz `PathGenerator` a una
 * implementación concreta, permitiendo personalizar la generación de rutas
 * para los archivos multimedia subidos.
 * Si no se especifica una implementación personalizada en la configuración,
 * se utilizará `DefaultPathGenerator`.
 */
final class MediaLibraryBindingsServiceProvider extends ServiceProvider
{
    /**
     * Registra servicios en el contenedor de Laravel.
     *
     * Enlaza la interfaz `PathGenerator` a la clase configurada.
     * Si la configuración no es válida, se usa `DefaultPathGenerator`.
     *
     * @return void
     */
    public function register(): void
    {
        // Vincula la interfaz PathGenerator a la implementación configurada.
        // Si no hay config, hace fallback a DefaultPathGenerator.
        $this->app->singleton(PathGenerator::class, function ($app) {
            /** @var class-string<PathGenerator>|null $concrete */
            $concrete = config('media-library.path_generator', DefaultPathGenerator::class);

            // Por si alguien deja la clave vacía en config por error.
            if (! is_string($concrete) || $concrete === '') {
                $concrete = DefaultPathGenerator::class;
            }

            return $app->make($concrete);
        });
    }

    /**
     * Método para inicializar servicios después del registro.
     *
     * Actualmente no se realiza ninguna acción en este método.
     *
     * @return void
     */
    public function boot(): void
    {
        // Nada que bootear por ahora.
    }
}