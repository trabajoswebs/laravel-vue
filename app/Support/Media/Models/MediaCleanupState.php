<?php

// 1. Declaración de tipos estrictos para evitar conversiones implícitas de tipos.
declare(strict_types=1);

// 2. Espacio de nombres para modelos de medios.
namespace App\Support\Media\Models;

// 3. Importación de la clase base Model de Eloquent.
use Illuminate\Database\Eloquent\Model;

/**
 * Estado durable para coordinar conversions pendientes y payloads de limpieza.
 */
final class MediaCleanupState extends Model
{
    /**
     * Nombre de la tabla en la base de datos.
     */
    protected $table = 'media_cleanup_states';

    /**
     * Nombre de la clave primaria.
     */
    protected $primaryKey = 'media_id';

    /**
     * Indica si la clave primaria es auto-incremental.
     * En este caso, no lo es, ya que usa el ID del medio como clave primaria.
     */
    public $incrementing = false;

    /**
     * Tipo de la clave primaria.
     * En este caso, es una cadena de texto (string), no un entero (int).
     */
    protected $keyType = 'string';

    /**
     * Atributos que se pueden asignar masivamente.
     */
    protected $fillable = [
        'media_id',          // ID del modelo Media asociado.
        'collection',        // Nombre de la colección de medios.
        'model_type',        // Tipo del modelo que posee el medio (polimorfismo).
        'model_id',          // ID del modelo que posee el medio.
        'conversions',       // Array con las conversiones esperadas.
        'payload',           // Array con el payload de limpieza.
        'flagged_at',        // Fecha y hora en que se marcó para limpieza.
        'payload_queued_at', // Fecha y hora en que se encoló el payload de limpieza.
    ];

    /**
     * Atributos que deben ser casteados a tipos nativos de PHP.
     */
    protected $casts = [
        'conversions'       => 'array',    // El atributo 'conversions' se almacena como un array en la base de datos.
        'payload'           => 'array',    // El atributo 'payload' se almacena como un array en la base de datos.
        'flagged_at'        => 'datetime', // El atributo 'flagged_at' se convierte a un objeto Carbon (datetime).
        'payload_queued_at' => 'datetime', // El atributo 'payload_queued_at' se convierte a un objeto Carbon (datetime).
    ];
}
