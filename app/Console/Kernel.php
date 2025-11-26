<?php

declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\Console\Kernel as InfrastructureKernel;

/**
 * Alias de compatibilidad para el Kernel de consola tras moverlo a Infrastructure\Console.
 */
class Kernel extends InfrastructureKernel
{
}
