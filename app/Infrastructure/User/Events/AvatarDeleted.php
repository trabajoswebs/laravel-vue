<?php

declare(strict_types=1);

namespace App\Infrastructure\User\Events;

use App\Application\User\Events\AvatarDeleted as DomainAvatarDeleted;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento de infraestructura que envuelve el evento de aplicación para Laravel.
 */
final class AvatarDeleted extends DomainAvatarDeleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(DomainAvatarDeleted $event)
    {
        parent::__construct($event->userId, $event->mediaId);
    }
}
