<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('uploads') || !Schema::hasColumn('uploads', 'owner_id')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        match ($driver) {
            'mysql', 'mariadb' => DB::statement('ALTER TABLE uploads MODIFY owner_id VARCHAR(64) NULL'),
            'pgsql' => DB::statement('ALTER TABLE uploads ALTER COLUMN owner_id TYPE VARCHAR(64)'),
            'sqlsrv' => DB::statement('ALTER TABLE uploads ALTER COLUMN owner_id NVARCHAR(64) NULL'),
            default => null, // sqlite y otros: no-op (sqlite permite almacenar texto por afinidad dinámica)
        };
    }

    public function down(): void
    {
        // owner_id como string(64) es el esquema canónico actual.
        // Revertir a BIGINT en rollback puede ser destructivo e inconsistente
        // con instalaciones limpias que ya nacen con string.
    }
};
