<?php

// 1. Declaración de tipos estrictos para evitar conversiones implícitas de tipos.
declare(strict_types=1);

// 2. Espacio de nombres para servicios de aplicación.
namespace App\Services;

// 3. Importaciones de traits, clases y facades necesarios.
use App\Services\Concerns\GuardsUploadedImage;
use App\Services\ImagePipeline\FallbackWorkflow;
use App\Services\ImagePipeline\ImagickWorkflow;
use App\Services\ImagePipeline\PipelineArtifacts;
use App\Services\ImagePipeline\PipelineConfig;
use App\Services\ImagePipeline\ImageProcessingException;
use App\Services\ImagePipeline\PipelineLogger;
use App\Support\Media\ConversionProfiles\FileConstraints;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Servicio que orquesta el pipeline de normalización y optimización de imágenes.
 * 
 * Este servicio actúa como el punto de entrada principal para el procesamiento de imágenes.
 * Decide qué flujo de trabajo utilizar (Imagick o Fallback con GD) basado en la
 * disponibilidad de las bibliotecas y configura todos los componentes necesarios.
 * 
 * @example
 * $pipeline = new ImagePipeline($fileConstraints);
 * $result = $pipeline->process($uploadedFile);
 * echo $result->path; // Ruta del archivo procesado
 * echo $result->width; // Ancho de la imagen
 */
final class ImagePipeline
{
    // 4. Usa el trait GuardsUploadedImage para añadir lógica de validación.
    use GuardsUploadedImage;

    // 5. Declaración de propiedades privadas e inmutables (readonly).
    private readonly PipelineConfig $config;
    private readonly PipelineLogger $logger;
    private readonly PipelineArtifacts $artifacts;
    private readonly ImagickWorkflow $imagickWorkflow;
    private readonly FallbackWorkflow $fallbackWorkflow;
    private readonly FileConstraints $constraints;

    /**
     * Constructor del servicio.
     *
     * @param FileConstraints $constraints Configuración de límites de archivo (tamaño, dimensiones, etc.).
     */
    public function __construct(
        FileConstraints $constraints,
        ?PipelineConfig $config = null,
        ?PipelineLogger $logger = null
    )
    {
        // Inicializa todos los componentes del pipeline
        $this->constraints = $constraints;
        $this->config = $config ?? PipelineConfig::fromConstraints($constraints);
        $this->logger = $logger ?? new PipelineLogger($this->config->logChannel, $this->config->debug);
        $this->artifacts = new PipelineArtifacts($this->logger);
        $this->imagickWorkflow = new ImagickWorkflow($this->config, $this->artifacts, $this->logger);
        $this->fallbackWorkflow = new FallbackWorkflow($this->config, $this->artifacts, $this->logger);
    }

    /**
     * Procesa un archivo de imagen subido y devuelve un objeto con el resultado.
     * 
     * Valida el archivo y, si está disponible, usa el flujo de trabajo de Imagick.
     * Si Imagick no está disponible, recurre al flujo de trabajo alternativo basado en GD.
     * 
     * @param UploadedFile $file El archivo de imagen subido.
     * @return ImagePipelineResult Objeto con la información de la imagen procesada.
     * @throws InvalidArgumentException Si el archivo no es válido, no se puede leer o no está permitido.
     */
    public function process(UploadedFile $file): ImagePipelineResult
    {
        // 6. Obtiene información del archivo subido (tamaño, ruta, MIME).
        $descriptor = $this->describeUploadedFile($file);

        // 7. Aplica validaciones adicionales al archivo subido.
        $guarded = $this->guardUploadedFile($file, $this->constraints);

        // 8. Combina la información del descriptor con la validación adicional.
        $descriptor = array_merge($descriptor, $guarded);

        // 9. Decide qué flujo de trabajo usar basado en la configuración.
        if (!$this->config->imagickAvailable) {
            return $this->fallbackWorkflow->process($file, $descriptor);
        }

        try {
            // 10. Intenta procesar con Imagick.
            return $this->imagickWorkflow->process($descriptor);
        } catch (ImageProcessingException $exception) {
            // 11. Si falla con ImageProcessingException, decide si recurrir a GD.
            if ($this->shouldFallbackToGd($exception) && $this->config->gdAvailable) {
                // 12. Registra un aviso si falla Imagick y se recurre a GD.
                $this->logger->log('warning', 'image_pipeline.imagick_failed', [
                    'error' => $this->logger->limit($exception->getMessage()),
                    'mime'  => $descriptor['mime'] ?? null,
                    'reason' => $exception->reason(),
                ]);

                // 13. Recurre al flujo de trabajo de GD.
                return $this->fallbackWorkflow->process($file, $descriptor);
            }

            // 14. Si no se puede recurrir a GD, relanza la excepción.
            throw $exception;
        } catch (\Throwable $exception) {
            // 15. Si falla con cualquier otra excepción, decide si recurrir a GD.
            if ($this->shouldFallbackToGd($exception) && $this->config->gdAvailable) {
                // 16. Registra un aviso si falla Imagick y se recurre a GD.
                $this->logger->log('warning', 'image_pipeline.imagick_failed', [
                    'error' => $this->logger->limit($exception->getMessage()),
                    'mime'  => $descriptor['mime'] ?? null,
                ]);

                // 17. Recurre al flujo de trabajo de GD.
                return $this->fallbackWorkflow->process($file, $descriptor);
            }

            // 18. Si no se puede recurrir a GD, relanza la excepción.
            throw $exception;
        }
    }

    /**
     * Extrae información esencial del archivo subido y la valida.
     * 
     * Obtiene el tamaño, la ruta real y el tipo MIME, y verifica que
     * cumplan con los límites y tipos permitidos configurados.
     * 
     * @param UploadedFile $file El archivo subido.
     * @return array{size:int, real_path:string, mime:string} Información del archivo.
     * @throws InvalidArgumentException Si el archivo no es válido o no cumple los requisitos.
     */
    private function describeUploadedFile(UploadedFile $file): array
    {
        // 19. Verifica si el archivo subido es válido según las reglas de Laravel.
        if (!$file->isValid()) {
            throw new InvalidArgumentException(__('image-pipeline.file_not_valid'));
        }

        // 20. Obtiene y valida el tamaño del archivo.
        $size = (int) ($file->getSize() ?? 0);
        if ($size <= 0 || $size > $this->config->maxBytes) {
            throw new InvalidArgumentException(__('image-pipeline.file_size_invalid'));
        }

        // 21. Obtiene y valida la ruta real del archivo.
        $realPath = $file->getRealPath();
        if (!\is_string($realPath) || $realPath === '' || !\is_readable($realPath)) {
            throw new InvalidArgumentException(__('image-pipeline.file_not_readable'));
        }

        // 22. Detecta el tipo MIME del archivo usando finfo.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($realPath);
        if (!isset($this->config->allowedMimes[$mime])) {
            throw new InvalidArgumentException(__('image-pipeline.mime_not_allowed', ['mime' => $mime]));
        }

        // 23. Devuelve la información extraída del archivo.
        return [
            'size' => $size,
            'real_path' => $realPath,
            'mime' => $mime,
        ];
    }

    /**
     * Decide si se debe recurrir al flujo de trabajo de GD en caso de error.
     *
     * @param \Throwable $exception La excepción capturada.
     * @return bool Verdadero si se debe recurrir a GD, falso en caso contrario.
     */
    private function shouldFallbackToGd(\Throwable $exception): bool
    {
        // 24. Si es una excepción específica del procesamiento de imágenes y es recuperable.
        if ($exception instanceof ImageProcessingException) {
            return $exception->isRecoverable();
        }

        // 25. Si es una excepción de Imagick, se considera recuperable.
        if ($exception instanceof \ImagickException) {
            return true;
        }

        // 26. Lista de mensajes de error que indican que se puede recurrir a GD.
        $recoverableMessages = [
            'image-pipeline.image_load_failed',
            'image-pipeline.gif_frame_invalid',
            'image-pipeline.gif_clone_failed',
        ];

        $message = $exception->getMessage();

        // 27. Comprueba si el mensaje de error coincide con alguno de los recuperables.
        foreach ($recoverableMessages as $code) {
            if (\str_contains($message, $code)) {
                return true;
            }
        }

        // 28. Si no es recuperable, devuelve falso.
        return false;
    }
}
