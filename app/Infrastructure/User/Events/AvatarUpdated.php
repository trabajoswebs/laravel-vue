<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Events;

use App\Application\User\Events\AvatarUpdated as DomainAvatarUpdated;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento adaptado para el bus de Laravel que envuelve el evento de aplicación.
 * 
 * Convierte el evento de dominio en un evento específico de Laravel
 * para que pueda ser manejado por el sistema de eventos del framework.
 */
final class AvatarUpdated
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public readonly int|string $userId;
    public readonly int|string $newMediaId;
    public readonly int|string|null $oldMediaId;
    public readonly ?string $version;
    public readonly string $collection;
    public readonly bool $replaced;
    public readonly ?string $url;

    /**
     * Constructor del evento adaptado.
     *
     * @param DomainAvatarUpdated $event Evento de dominio a envolver
     */
    public function __construct(DomainAvatarUpdated $event)
    {
        $this->userId = $event->userId;
        $this->newMediaId = $event->newMediaId;
        $this->oldMediaId = $event->oldMediaId;
        $this->version = $event->version;
        $this->collection = $event->collection;
        $this->replaced = $event->replaced;
        $this->url = $event->url;
    }
}
