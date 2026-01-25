<?php // Migración para crear la tabla tenants con owner y timestamps

declare(strict_types=1); // Fuerza tipado estricto para coherencia

use Illuminate\Database\Migrations\Migration; // Importa clase base de migraciones
use Illuminate\Database\Schema\Blueprint; // Importa generador de esquema
use Illuminate\Support\Facades\Schema; // Importa fachada Schema

return new class extends Migration // Clase anónima que define la migración
{
    /**
     * Run the migrations.
     */
    public function up(): void // Crea la tabla tenants
    {
        Schema::create('tenants', function (Blueprint $table): void { // Define estructura de tenants
            $table->id(); // Primary key incremental para el tenant
            $table->string('name'); // Nombre legible del tenant
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete()->index('tenants_owner_user_id_index'); // FK al usuario dueño con índice
            $table->timestamps(); // Marcas de tiempo de creación/actualización
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void // Revierte la creación de la tabla
    {
        Schema::dropIfExists('tenants'); // Elimina la tabla si existe
    }
};
