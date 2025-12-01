<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers;

use App\Infrastructure\Media\Listeners\RunPendingMediaCleanup;
use App\Infrastructure\Media\Listeners\QueueAvatarPostProcessing;
use App\Infrastructure\User\Events\AvatarDeleted;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

/**
 * Proveedor de servicios para la gestión de eventos de la aplicación.
 * 
 * Registra los listeners para eventos específicos de la aplicación,
 * incluyendo eventos de medios y avatares.
 */
class EventServiceProvider extends ServiceProvider
{
    /**
     * Mapeo explícito de eventos → listeners (sin discovery).
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        // Al borrar avatar: aquí puedes enganchar otros listeners (CDN, métricas…)
        AvatarDeleted::class => [
            // \App\Listeners\User\PurgeCdnCache::class,
        ],

        // Al completar conversiones de medios: procesamiento posterior y limpieza
        ConversionHasBeenCompletedEvent::class => [
            QueueAvatarPostProcessing::class,      // Cola procesamiento de avatares
            RunPendingMediaCleanup::class,        // Ejecuta limpieza de artefactos pendientes
        ],

    ];

    /**
     * No usamos event discovery: todo queda registrado en $listen.
     * 
     * @return bool False para deshabilitar la detección automática de eventos
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }

    /**
     * Inicializa el proveedor de eventos.
     * 
     * No requiere lógica adicional ya que el mapeo en $listen es suficiente.
     */
    public function boot(): void
    {
        // Nada más: el mapeo en $listen es suficiente.
    }
}
