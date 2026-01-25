<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Quarantine;

/**
 * Estados posibles de un artefacto en cuarentena.
 * 
 * Representa los diferentes estados por los que puede pasar un archivo
 * mientras está en el proceso de cuarentena y validación.
 */
enum QuarantineState: string
{
    /**
     * Estado inicial: archivo subido pero aún no procesado.
     */
    case PENDING = 'pending';

    /**
     * El archivo está siendo escaneado (antivirus, validación, etc.).
     */
    case SCANNING = 'scanning';

    /**
     * El archivo ha sido validado y es seguro (limpio).
     */
    case CLEAN = 'clean';

    /**
     * El archivo ha sido detectado como infectado o malicioso.
     */
    case INFECTED = 'infected';

    /**
     * El archivo ha sido promovido a su destino final.
     */
    case PROMOTED = 'promoted';

    /**
     * El archivo ha fallado en alguna etapa del proceso de validación.
     */
    case FAILED = 'failed';

    /**
     * El archivo ha expirado por tiempo de vida útil (TTL).
     */
    case EXPIRED = 'expired';
}
