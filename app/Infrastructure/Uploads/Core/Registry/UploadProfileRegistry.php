<?php // Registro de perfiles de upload disponibles

declare(strict_types=1); // Tipado estricto

namespace App\Infrastructure\Uploads\Core\Registry; // Namespace de infraestructura de uploads

use App\Domain\Uploads\UploadProfile; // Perfil de dominio
use App\Domain\Uploads\UploadProfileId; // VO de ID de perfil
use InvalidArgumentException; // Excepción para perfiles desconocidos

/**
 * Resuelve perfiles de upload por identificador.
 */
final class UploadProfileRegistry // Registro inmutable de perfiles
{
    /**
     * @param array<string, UploadProfile> $profiles Map de perfiles indexados por id
     */
    public function __construct(private readonly array $profiles) // Injerta perfiles definidos en providers
    {
    }

    /**
     * Obtiene un perfil por su identificador.
     *
     * @param UploadProfileId $id Identificador del perfil
     * @return UploadProfile Perfil configurado
     */
    public function get(UploadProfileId $id): UploadProfile // Devuelve el perfil solicitado
    {
        $key = (string) $id; // Convierte VO a string para indexar

        if (! array_key_exists($key, $this->profiles)) { // Valida existencia
            throw new InvalidArgumentException("Perfil de upload no registrado: {$key}"); // Informa perfil faltante
        }

        return $this->profiles[$key]; // Devuelve el perfil encontrado
    }
}
