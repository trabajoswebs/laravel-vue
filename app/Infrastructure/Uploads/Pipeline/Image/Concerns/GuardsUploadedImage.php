<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Image\Concerns;

use App\Infrastructure\Uploads\Core\Contracts\FileConstraints;
use Illuminate\Http\UploadedFile;
use App\Support\Logging\SecurityLogger;
use RuntimeException;

/**
 * Centraliza validaciones defensivas previas a procesar imágenes.
 *
 * - Reutiliza FileConstraints para validar tamaño, MIME y ratio anti-bombas.
 * - Escanea las cabeceras del archivo en busca de payloads sospechosos.
 * 
 * @example
 * class MyService
 * {
 *     use GuardsUploadedImage;
 * 
 *     public function processImage(UploadedFile $file, FileConstraints $constraints)
 *     {
 *         $dims = $this->guardUploadedFile($file, $constraints);
 *         // Procesar imagen sabiendo que es segura
 *     }
 * }
 */
trait GuardsUploadedImage
{
    /**
     * Valida un archivo subido y devuelve dimensiones confiables.
     * 
     * Aplica las restricciones de tamaño, MIME y dimensiones definidas en FileConstraints.
     * Luego escanea el archivo en busca de payloads sospechosos.
     * 
     * @param UploadedFile $file Archivo subido a validar.
     * @param FileConstraints $constraints Configuración de restricciones.
     * @return array{width:int,height:int} Ancho y alto de la imagen.
     * @throws RuntimeException Si el archivo no cumple las restricciones o contiene un payload sospechoso.
     */
    protected function guardUploadedFile(UploadedFile $file, FileConstraints $constraints): array
    {
        // Valida el archivo contra las restricciones (tamaño, MIME, dimensiones, etc.)
        [$width, $height] = $constraints->probeAndAssert($file);

        // Escanea el archivo para detectar posibles payloads maliciosos
        $this->scanPayloads($file->getRealPath());

        return [
            'width'  => $width,
            'height' => $height,
        ];
    }

    /**
     * Busca firmas sospechosas al inicio del archivo, abortando si encuentra coincidencias.
     * 
     * Lee un buffer inicial del archivo y lo compara contra patrones de configuración
     * que pueden indicar contenido malicioso o archivos falsificados.
     * 
     * @param string|null $path Ruta del archivo a escanear.
     * @throws RuntimeException Si se encuentra un payload sospechoso.
     */
    private function scanPayloads(?string $path): void
    {
        if ($path === null) {
            throw new RuntimeException(__('image-pipeline.file_not_readable'));
        }

        $patterns = config('image-pipeline.suspicious_payload_patterns', []);
        if ($patterns === [] || !is_readable($path)) {
            return;
        }

        // Lee los primeros 64KB del archivo
        $buffer = @file_get_contents($path, false, null, 0, 64 * 1024);
        if ($buffer === false || $buffer === '') {
            return;
        }

        foreach ($patterns as $pattern) {
            // Valida que el patrón sea una expresión regular válida
            if (@preg_match($pattern, '') === false) {
                continue;
            }

            // Busca coincidencias en el buffer
            if (preg_match($pattern, $buffer) === 1) {
                SecurityLogger::warning('image_pipeline_suspicious_payload_detected', [
                    'pattern' => $pattern,
                    'path' => basename($path),
                    'sha256' => hash('sha256', $buffer)
                ]);

                throw new RuntimeException(__('image-pipeline.suspicious_payload'));
            }
        }
    }
}
