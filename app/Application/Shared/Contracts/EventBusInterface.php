<?php

declare(strict_types=1);

namespace App\Application\Shared\Contracts;

/**
 * Interfaz para servicios de bus de eventos.
 */
interface EventBusInterface
{
    /**
     * Despacha un evento al bus de eventos.
     *
     * @param object $event Evento a despachar
     */
    public function dispatch(object $event): void;
}
