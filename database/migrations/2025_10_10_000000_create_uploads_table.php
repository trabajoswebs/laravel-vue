<?php // Migración para la tabla uploads (documentos no imagen)

declare(strict_types=1); // Tipado estricto

use Illuminate\Database\Migrations\Migration; // Clase base de migración
use Illuminate\Database\Schema\Blueprint; // Builder de esquema
use Illuminate\Support\Facades\Schema; // Facade Schema

return new class extends Migration // Clase anónima de migración
{
    /**
     * Run the migrations.
     */
    public function up(): void // Crea la tabla uploads
    {
        Schema::create('uploads', function (Blueprint $table): void { // Define estructura
            $table->uuid('id')->primary(); // PK UUID para rastreo
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete()->index('uploads_tenant_id_index'); // FK tenant con índice
            $table->string('owner_type')->nullable(); // Tipo de propietario (morph)
            $table->unsignedBigInteger('owner_id')->nullable()->index('uploads_owner_id_index'); // ID del propietario con índice
            $table->string('profile_id'); // ID de perfil de upload
            $table->string('disk'); // Disco de almacenamiento
            $table->string('path'); // Path relativo en el disco
            $table->string('mime'); // MIME real del archivo
            $table->unsignedBigInteger('size'); // Tamaño en bytes
            $table->string('checksum')->nullable(); // Checksum opcional
            $table->string('original_name')->nullable(); // Nombre original (para logging)
            $table->string('visibility')->default('private'); // Visibilidad del archivo
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete()->index('uploads_created_by_user_id_index'); // Usuario que subió el archivo
            $table->timestamps(); // Timestamps
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void // Elimina la tabla uploads
    {
        Schema::dropIfExists('uploads'); // Borra la tabla uploads
    }
};
