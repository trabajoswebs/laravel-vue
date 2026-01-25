<?php // Migración para agregar current_tenant_id a usuarios

declare(strict_types=1); // Enforce tipado estricto

use Illuminate\Database\Migrations\Migration; // Base de migraciones
use Illuminate\Database\Schema\Blueprint; // Constructor de esquema
use Illuminate\Support\Facades\Schema; // Fachada Schema

return new class extends Migration // Clase anónima de migración
{
    /**
     * Run the migrations.
     */
    public function up(): void // Agrega columna current_tenant_id
    {
        Schema::table('users', function (Blueprint $table): void { // Modifica la tabla users
            $table->foreignId('current_tenant_id')->nullable()->after('email')->constrained('tenants')->nullOnDelete()->index('users_current_tenant_id_index'); // FK opcional al tenant actual con índice dedicado
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void // Revierte la columna
    {
        Schema::table('users', function (Blueprint $table): void { // Opera sobre la tabla users
            $table->dropConstrainedForeignId('current_tenant_id'); // Quita la FK y la columna
        });
    }
};
