<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\Media\RunPendingMediaCleanup;
use App\Listeners\User\QueueAvatarPostProcessing;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;
use App\Events\User\AvatarDeleted;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

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

        ConversionHasBeenCompletedEvent::class => [
            QueueAvatarPostProcessing::class,
            RunPendingMediaCleanup::class,
        ],

    ];

    /**
     * No usamos event discovery: todo queda registrado en $listen.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }

    public function boot(): void
    {
        // Nada más: el mapeo en $listen es suficiente.
    }
}
