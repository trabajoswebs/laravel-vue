<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Adapters;

use App\Application\Shared\Contracts\EventBusInterface;
use App\Application\User\Events\AvatarDeleted as DomainAvatarDeleted;
use App\Application\User\Events\AvatarUpdated as DomainAvatarUpdated;
use App\Infrastructure\User\Events\AvatarDeleted as LaravelAvatarDeleted;
use App\Infrastructure\User\Events\AvatarUpdated as LaravelAvatarUpdated;

/**
 * Adaptador del bus de eventos de Laravel.
 * 
 * Convierte eventos de dominio en eventos específicos de Laravel
 * antes de despacharlos al sistema de eventos del framework.
 */
final class LaravelEventBus implements EventBusInterface
{
    /**
     * Despacha un evento al bus de eventos de Laravel.
     *
     * @param object $event Evento a despachar (puede ser de dominio o de Laravel)
     */
    public function dispatch(object $event): void
    {
        if ($event instanceof DomainAvatarUpdated) {
            $event = new LaravelAvatarUpdated($event);  // Convierte evento de dominio a evento de Laravel
        } elseif ($event instanceof DomainAvatarDeleted) {
            $event = new LaravelAvatarDeleted($event);  // Convierte evento de dominio a evento de Laravel
        }

        event($event);  // Despacha el evento al sistema de eventos de Laravel
    }
}
