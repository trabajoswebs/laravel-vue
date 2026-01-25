<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Adapters;

use App\Application\Shared\Contracts\EventBusInterface;

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
        event($event);  // Despacha el evento al sistema de eventos de Laravel
    }
}
