<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Carbon\Carbon;

class CleanAuditLogs extends Command
{
    /**
     * Nombre y firma del comando de consola.
     * Define cómo se invoca el comando y sus posibles opciones.
     * - {--days=90} : Opción que permite especificar cuántos días de logs mantener (por defecto 90).
     * - {--force} : Opción que permite forzar la ejecución sin pedir confirmación al usuario.
     *
     * @var string
     */
    protected $signature = 'audit:clean {--days=90 : Number of days to keep logs} {--force : Force deletion without confirmation}';

    /**
     * Descripción del comando de consola.
     * Se muestra en la lista de comandos disponibles.
     *
     * @var string
     */
    protected $description = 'Clean old audit and security logs based on retention policy';

    /**
     * Método principal que ejecuta el comando.
     * Orquesta la lógica de limpieza de logs.
     */
    public function handle()
    {
        // Lee el número de días de retención de la opción --days o de la configuración por defecto.
        $days = $this->resolveRetentionDays();
        // Lee si se pasó la opción --force.
        $force = (bool) $this->option('force');

        // Si los días son 0, no tiene sentido continuar. Se imprime un aviso y termina.
        if ($days === 0) {
            $this->warn('⚠️ El parámetro --days debe ser mayor que 0 para ejecutar la limpieza.');
            return Command::SUCCESS;
        }

        // Si no se usó --force, se pide confirmación al usuario antes de proceder con la eliminación.
        if (! $force && ! $this->confirm("Se eliminarán logs con más de {$days} días. ¿Deseas continuar?")) {
            $this->info('Operación cancelada.');
            return Command::SUCCESS;
        }

        // Mensaje informativo sobre el inicio de la limpieza.
        $this->info("🧹 Limpiando logs de auditoría más antiguos de {$days} días...");

        // Ruta base donde se almacenan los logs.
        $logPath = storage_path('logs');
        // Fecha límite: cualquier log con fecha anterior a esta se considera para eliminación.
        $cutoffDate = Carbon::now()->subDays($days);
        // Fecha de hoy, para evitar eliminar logs del día actual.
        $today = Carbon::today();
        // Contadores para reportar al final cuántos archivos se eliminaron y cuánto espacio se liberó.
        $totalDeletedFiles = 0;
        $totalDeletedSize = 0;

        // Itera sobre los patrones de archivos de log definidos (audit, security, laravel).
        foreach ($this->logPatterns() as $label => $pattern) {
            // Llama al método que realmente limpia los archivos para este patrón específico.
            $result = $this->cleanLogFiles($logPath, $pattern, $cutoffDate, $today);
            // Acumula los resultados de cada iteración.
            $totalDeletedFiles += $result['files'];
            $totalDeletedSize += $result['size'];

            // Si se eliminaron archivos de este tipo, imprime un mensaje resumen.
            if ($result['files'] > 0) {
                $this->line("• {$label}: {$result['files']} archivo(s) eliminados");
            }
        }

        // Mensajes finales con el resumen de la operación.
        if ($totalDeletedFiles > 0) {
            $this->info("✅ Se eliminaron {$totalDeletedFiles} archivos de log");
            $this->info("💾 Espacio liberado: " . $this->formatBytes($totalDeletedSize));
        } else {
            $this->info('ℹ️ No se encontraron archivos de log para eliminar');
        }

        // Llama al método que limpia directorios vacíos que hayan quedado tras la eliminación.
        $this->cleanEmptyDirectories($logPath);

        $this->info("🎯 Limpieza de logs completada");

        return Command::SUCCESS;
    }

    /**
     * Limpia archivos de log específicos basados en un patrón y una fecha límite.
     * Recorre los archivos que coinciden con el patrón, verifica su fecha de modificación
     * y los elimina si son más antiguos que la fecha de corte y pasan la verificación de exclusión.
     *
     * @param string $path Ruta base donde buscar los archivos.
     * @param string $pattern Patrón de glob para encontrar los archivos (e.g., 'audit-*.log').
     * @param Carbon $cutoffDate Fecha límite para la eliminación.
     * @param Carbon $today Fecha de hoy para evitar eliminar logs del día actual.
     * @return array Resultado con el número de archivos eliminados y el tamaño total liberado.
     */
    private function cleanLogFiles(string $path, string $pattern, Carbon $cutoffDate, Carbon $today): array
    {
        // Busca archivos que coincidan con el patrón en la ruta dada.
        $files = File::glob($path . '/' . $pattern) ?: [];
        $deletedFiles = 0;
        $deletedSize = 0;

        foreach ($files as $file) {
            // Obtiene información sobre el archivo (tamaño, fecha de modificación, etc.).
            $fileInfo = File::stat($file);
            // Si no se puede leer la información del archivo, se omite.
            if (! $fileInfo) {
                continue;
            }

            // Crea un objeto Carbon a partir de la fecha de modificación del archivo.
            $fileDate = Carbon::createFromTimestamp($fileInfo['mtime']);

            // Verifica si el archivo es más antiguo que la fecha de corte Y si debe ser eliminado según otras reglas.
            if ($fileDate->lt($cutoffDate) && $this->shouldDeleteFile($file, $today)) {
                $size = $fileInfo['size'] ?? 0; // Toma el tamaño del archivo o 0 si no está disponible.

                // Intenta eliminar el archivo.
                if (File::delete($file)) {
                    $deletedFiles++;
                    $deletedSize += $size;
                    // Imprime un mensaje indicando que el archivo fue eliminado.
                    $this->line('🗑️ Eliminado: ' . basename($file));
                }
            }
        }

        // Devuelve un array con el conteo de archivos eliminados y el tamaño total liberado.
        return [
            'files' => $deletedFiles,
            'size' => $deletedSize,
        ];
    }

    /**
     * Determina si un archivo específico debe ser eliminado según reglas de exclusión.
     * Por ejemplo, no se eliminan logs del día actual ni logs críticos.
     *
     * @param string $file Ruta completa al archivo.
     * @param Carbon $today Fecha de hoy.
     * @return bool True si el archivo puede ser eliminado, false si debe excluirse.
     */
    private function shouldDeleteFile(string $file, Carbon $today): bool
    {
        // Obtiene solo el nombre del archivo (sin la ruta).
        $filename = basename($file);

        // No eliminar logs del día actual. Evita borrar logs recientes accidentalmente.
        if (str_contains($filename, $today->format('Y-m-d'))) {
            return false;
        }

        // No eliminar logs que contengan 'error' o 'critical' en su nombre.
        // Estos tipos de logs suelen ser importantes para troubleshooting.
        if (str_contains($filename, 'error') || str_contains($filename, 'critical')) {
            return false;
        }

        // Si pasó todas las reglas de exclusión, se puede eliminar.
        return true;
    }

    /**
     * Elimina directorios vacíos dentro de una ruta específica.
     * Se llama al finalizar la limpieza de archivos para dejar la estructura limpia.
     *
     * @param string $path Ruta base donde buscar directorios vacíos.
     */
    private function cleanEmptyDirectories(string $path): void
    {
        // Obtiene todos los directorios directos dentro de la ruta.
        $directories = File::directories($path);

        foreach ($directories as $directory) {
            // Verifica si el directorio está vacío (no tiene archivos ni subdirectorios).
            if (empty(File::files($directory)) && empty(File::directories($directory))) {
                // Elimina el directorio vacío.
                File::deleteDirectory($directory);
                // Imprime un mensaje indicando que el directorio fue eliminado.
                $this->line("📁 Directorio vacío eliminado: " . basename($directory));
            }
        }
    }

    /**
     * Formatea un tamaño en bytes a un formato legible (B, KB, MB, GB, TB).
     *
     * @param int $bytes Tamaño en bytes.
     * @return string Tamaño formateado como cadena.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        // Divide el tamaño por 1024 hasta que sea menor o se alcance la unidad máxima.
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        // Devuelve el tamaño redondeado a 2 decimales junto con la unidad correspondiente.
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Devuelve los patrones de archivos de log que el comando debe procesar.
     * Se puede extender fácilmente para incluir otros tipos de logs.
     *
     * @return array<string, string> Mapa de etiquetas a patrones de glob.
     */
    private function logPatterns(): array
    {
        return [
            'Auditoría' => 'audit-*.log',    // Archivos que empiezan con 'audit-' y terminan en '.log'
            'Seguridad' => 'security-*.log', // Archivos que empiezan con 'security-' y terminan en '.log'
            'Laravel' => 'laravel-*.log',    // Archivos que empiezan con 'laravel-' y terminan en '.log'
        ];
    }

    /**
     * Resuelve el número de días de retención a aplicar.
     * Prioriza el valor pasado como opción (--days) y si no está, usa la configuración por defecto.
     * Asegura que el valor no sea negativo.
     *
     * @return int Número de días de retención.
     */
    private function resolveRetentionDays(): int
    {
        $optionValue = $this->option('days');
        // Si no se pasó --days, usa la configuración 'audit.retention_days' o 90 por defecto.
        $days = $optionValue !== null ? (int) $optionValue : (int) config('audit.retention_days', 90);

        // Asegura que el número de días no sea negativo.
        return max(0, $days);
    }
}
