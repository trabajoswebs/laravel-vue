<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Contracts;

use Spatie\MediaLibrary\HasMedia;

/**
 * Marca modelos que pueden poseer media administrado por Spatie.
 */
interface MediaOwner extends HasMedia
{
}
