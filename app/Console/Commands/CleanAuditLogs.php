<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Carbon\Carbon;

class CleanAuditLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audit:clean {--days=90 : Number of days to keep logs} {--force : Force deletion without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean old audit and security logs based on retention policy';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = (int) $this->option('days');
        $force = $this->option('force');
        
        $this->info("🧹 Limpiando logs de auditoría más antiguos de {$days} días...");
        
        $logPath = storage_path('logs');
        $cutoffDate = Carbon::now()->subDays($days);
        $deletedFiles = 0;
        $deletedSize = 0;
        
        // Limpiar logs de auditoría
        $auditLogs = $this->cleanLogFiles($logPath, 'audit-*.log', $cutoffDate, $deletedFiles, $deletedSize);
        
        // Limpiar logs de seguridad
        $securityLogs = $this->cleanLogFiles($logPath, 'security-*.log', $cutoffDate, $deletedFiles, $deletedSize);
        
        // Limpiar logs de Laravel antiguos
        $laravelLogs = $this->cleanLogFiles($logPath, 'laravel-*.log', $cutoffDate, $deletedFiles, $deletedSize);
        
        if ($deletedFiles > 0) {
            $this->info("✅ Se eliminaron {$deletedFiles} archivos de log");
            $this->info("💾 Espacio liberado: " . $this->formatBytes($deletedSize));
        } else {
            $this->info("ℹ️ No se encontraron archivos de log para eliminar");
        }
        
        // Limpiar directorios vacíos
        $this->cleanEmptyDirectories($logPath);
        
        $this->info("🎯 Limpieza de logs completada");
        
        return Command::SUCCESS;
    }
    
    /**
     * Clean log files based on pattern and date.
     */
    private function cleanLogFiles(string $path, string $pattern, Carbon $cutoffDate, int &$deletedFiles, int &$deletedSize): array
    {
        $files = File::glob($path . '/' . $pattern);
        $deleted = [];
        
        foreach ($files as $file) {
            $fileInfo = File::stat($file);
            $fileDate = Carbon::createFromTimestamp($fileInfo['mtime']);
            
            if ($fileDate->lt($cutoffDate)) {
                if ($this->shouldDeleteFile($file)) {
                    $size = $fileInfo['size'];
                    File::delete($file);
                    
                    $deletedFiles++;
                    $deletedSize += $size;
                    $deleted[] = basename($file);
                    
                    $this->line("🗑️ Eliminado: " . basename($file));
                }
            }
        }
        
        return $deleted;
    }
    
    /**
     * Check if file should be deleted.
     */
    private function shouldDeleteFile(string $file): bool
    {
        $filename = basename($file);
        
        // No eliminar logs del día actual
        if (str_contains($filename, Carbon::now()->format('Y-m-d'))) {
            return false;
        }
        
        // No eliminar logs de error críticos
        if (str_contains($filename, 'error') || str_contains($filename, 'critical')) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Clean empty directories.
     */
    private function cleanEmptyDirectories(string $path): void
    {
        $directories = File::directories($path);
        
        foreach ($directories as $directory) {
            if (empty(File::files($directory)) && empty(File::directories($directory))) {
                File::deleteDirectory($directory);
                $this->line("📁 Directorio vacío eliminado: " . basename($directory));
            }
        }
    }
    
    /**
     * Format bytes to human readable format.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
