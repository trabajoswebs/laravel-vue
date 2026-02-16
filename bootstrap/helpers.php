<?php

use App\Models\Tenant;

if (!function_exists('tenant')) {
    /**
     * Helper global para obtener el tenant actual resuelto por el middleware.
     */
    function tenant(): ?Tenant
    {
        return Tenant::current();
    }
}
