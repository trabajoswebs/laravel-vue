<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Uploads\Pipeline\Quarantine\QuarantineRepository;
use Illuminate\Console\Command;

/**
 * Comando de consola para limpiar los archivos "sidecar" huérfanos del cuarentena.
 * 
 * Los archivos "sidecar" son archivos auxiliares (como metadatos o hash) que se almacenan
 * junto con los archivos principales en la cuarentena. Este comando elimina aquellos
 * archivos sidecar que ya no tienen un archivo principal asociado (huérfanos).
 */
class QuarantineCleanupSidecarsCommand extends Command
{
    /**
     * Firma del comando que se usa para ejecutarlo desde la consola.
     * 
     * @var string
     */
    protected $signature = 'quarantine:cleanup-sidecars';

    /**
     * Descripción del comando que se muestra cuando se lista los comandos disponibles.
     * 
     * @var string
     */
    protected $description = 'Remove orphaned quarantine sidecar files (hash/metadata).';

    /**
     * Constructor del comando.
     * 
     * Inicializa el comando con el repositorio de cuarentena necesario para
     * realizar las operaciones de limpieza.
     * 
     * @param QuarantineRepository $repository Repositorio para operaciones de cuarentena
     */
    public function __construct(private readonly QuarantineRepository $repository)
    {
        parent::__construct();
    }

    /**
     * Método principal que ejecuta la lógica del comando.
     * 
     * Verifica si el repositorio soporta la limpieza de archivos sidecar,
     * y si es así, ejecuta la operación de limpieza y muestra el resultado.
     * 
     * @return int Código de resultado del comando (SUCCESS o FAILURE)
     */
    public function handle(): int
    {
        // Verificar si el método de limpieza existe en el repositorio
        if (!method_exists($this->repository, 'cleanupOrphanedSidecars')) {
            $this->error('Quarantine repository does not support sidecar cleanup.');
            return Command::FAILURE;
        }

        // Ejecutar la limpieza de archivos sidecar huérfanos
        $cleaned = $this->repository->cleanupOrphanedSidecars();

        // Mostrar mensaje de éxito con la cantidad de archivos eliminados
        $this->info("Cleaned {$cleaned} orphaned quarantine sidecar file(s).");

        // Retornar código de éxito
        return Command::SUCCESS;
    }
}
