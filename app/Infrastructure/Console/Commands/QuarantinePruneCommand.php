<?php

declare(strict_types=1);

namespace App\Infrastructure\Console\Commands;

use App\Infrastructure\Uploads\Pipeline\Quarantine\QuarantineRepository;
use Illuminate\Console\Command;

/**
 * Comando de consola para eliminar archivos obsoletos de la cuarentena.
 * 
 * Este comando limpia los archivos de la cuarentena que han superado su tiempo
 * de vida útil (TTL - Time To Live), basándose en los metadatos por perfil o
 * un valor de respaldo configurado.
 */
class QuarantinePruneCommand extends Command
{
    /**
     * Firma del comando que se usa para ejecutarlo desde la consola.
     * 
     * Permite especificar opcionalmente las horas para el TTL de respaldo.
     * 
     * @var string
     */
    protected $signature = 'quarantine:prune {--hours= : Fallback TTL (hours) when metadata does not define one}';

    /**
     * Descripción del comando que se muestra cuando se lista los comandos disponibles.
     * 
     * @var string
     */
    protected $description = 'Remove stale quarantine artifacts honoring state/TTL metadata per profile.';

    /**
     * Constructor del comando.
     * 
     * Inicializa el comando con el repositorio de cuarentena necesario para
     * realizar las operaciones de poda (prune).
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
     * Obtiene el tiempo de vida útil (TTL) de respaldo de la opción o configuración,
     * verifica si el repositorio soporta la operación de poda, y si es así,
     * ejecuta la operación y muestra el resultado.
     * 
     * @return int Código de resultado del comando (SUCCESS o FAILURE)
     */
    public function handle(): int
    {
        // Obtener el valor de horas de la opción --hours
        $fallback = $this->option('hours');

        // Determinar las horas a usar: opción proporcionada o valor por defecto de configuración
        $hours = (int) ($fallback !== null && $fallback !== ''
            ? $fallback
            : config('image-pipeline.quarantine_pending_ttl_hours', 24));

        // Asegurar que las horas sean positivas, de lo contrario usar 24 como valor por defecto
        $hours = $hours > 0 ? $hours : 24;

        // Verificar si el método de poda existe en el repositorio
        if (!method_exists($this->repository, 'pruneStaleFiles')) {
            $this->error('Quarantine repository does not support pruning.');
            return Command::FAILURE;
        }

        // Ejecutar la operación de poda de archivos obsoletos
        $removed = $this->repository->pruneStaleFiles($hours);

        // Mostrar mensaje de éxito con la cantidad de archivos eliminados
        // y el TTL de respaldo utilizado (los metadatos por perfil tienen prioridad)
        $this->info("Pruned {$removed} quarantine artifact(s) (fallback TTL {$hours}h; profile metadata takes precedence).");

        // Retornar código de éxito
        return Command::SUCCESS;
    }
}
