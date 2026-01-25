<?php // Migración para la tabla pivote tenant_user con roles

declare(strict_types=1); // Enforce tipado estricto

use Illuminate\Database\Migrations\Migration; // Base de migraciones
use Illuminate\Database\Schema\Blueprint; // Constructor de esquema
use Illuminate\Support\Facades\Schema; // Fachada Schema

return new class extends Migration // Clase anónima para la migración
{
    /**
     * Run the migrations.
     */
    public function up(): void // Crea la tabla pivote tenant_user
    {
        Schema::create('tenant_user', function (Blueprint $table): void { // Define la estructura pivote
            $table->id(); // Llave primaria incremental para mantener trazabilidad
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete(); // FK al tenant con borrado cascada
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // FK al usuario con borrado cascada
            $table->string('role', 32)->default('member'); // Rol simple dentro del tenant
            $table->timestamps(); // Marcas de tiempo estándar
            $table->unique(['tenant_id', 'user_id']); // Evita duplicar asignaciones de usuario por tenant
            $table->index('tenant_id', 'tenant_user_tenant_id_index'); // Índice dedicado para queries por tenant
            $table->index('user_id', 'tenant_user_user_id_index'); // Índice dedicado para queries por usuario
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void // Revierte la tabla pivote
    {
        Schema::dropIfExists('tenant_user'); // Elimina la tabla pivote
    }
};
