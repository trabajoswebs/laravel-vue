<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;

final class MediaLibraryBindingsServiceProvider extends ServiceProvider
{
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

    public function boot(): void
    {
        // Nada que bootear por ahora.
    }
}
