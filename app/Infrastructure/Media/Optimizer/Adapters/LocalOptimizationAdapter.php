<?php

declare(strict_types=1);

namespace App\Infrastructure\Media\Optimizer\Adapters;

use RuntimeException;
use Spatie\ImageOptimizer\OptimizerChain;

/**
 * Adaptador para la optimización local de archivos con validaciones previas.
 *
 * Esta clase encapsula la lógica de optimización de archivos locales, verificando
 * que cumplan con ciertos criterios (tamaño, tipo MIME) antes de aplicar una cadena
 * de optimización. Utiliza la biblioteca `spatie/image-optimizer` para realizar
 * la optimización real del archivo.
 */
final class LocalOptimizationAdapter
{
    /**
     * Constructor de la clase.
     *
     * @param OptimizerChain $optimizer Cadena de optimizadores a aplicar.
     * @param int $maxFileSize Tamaño máximo permitido del archivo en bytes.
     * @param array<int, string> $allowedMimes Lista de tipos MIME permitidos.
     */
    public function __construct(
        private readonly OptimizerChain $optimizer,
        private readonly int $maxFileSize,
        private readonly array $allowedMimes,
    ) {}

    /**
     * Optimiza un archivo local si cumple con las validaciones de tamaño y tipo MIME.
     *
     * Realiza las siguientes validaciones:
     * - El archivo debe existir y ser legible.
     * - El tipo MIME debe estar en la lista de permitidos.
     * - El tipo MIME debe coincidir con el esperado (si se proporciona).
     * - El tamaño del archivo no debe exceder el límite permitido.
     *
     * Luego, aplica la cadena de optimización y compara tamaños antes y después.
     *
     * @param string $fullPath Ruta completa del archivo a optimizar.
     * @param string|null $expectedMime Tipo MIME esperado del archivo. Si se proporciona,
     *                                  se compara con el tipo MIME detectado.
     *
     * @throws RuntimeException Si el archivo no es legible, no cumple con las validaciones,
     *                          o si ocurre un error inesperado durante la optimización.
     *
     * @return array{bytes_before: int, bytes_after: int, optimized: bool}
     *         Un arreglo con el tamaño antes y después de la optimización,
     *         y un indicador booleano que dice si hubo reducción de tamaño.
     */
    public function optimize(string $fullPath, ?string $expectedMime = null): array
    {
        if (!$this->isReadableFile($fullPath)) {
            throw new RuntimeException('file_not_readable');
        }

        $mime = $this->detectMime($fullPath);
        if ($expectedMime !== null && $mime !== '' && $mime !== $expectedMime) {
            throw new RuntimeException('mime_mismatch');
        }

        if (!\in_array($mime, $this->allowedMimes, true)) {
            throw new RuntimeException('mime_not_allowed');
        }

        $before = filesize($fullPath) ?: 0;
        if ($before <= 0) {
            throw new RuntimeException('empty_file');
        }
        if ($before > $this->maxFileSize) {
            throw new RuntimeException('file_too_large');
        }

        $this->optimizer->optimize($fullPath);
        clearstatcache(true, $fullPath); // Limpia la caché de estado del archivo para obtener el tamaño actualizado.
        $after = filesize($fullPath) ?: $before;

        return [
            'bytes_before' => $before,
            'bytes_after'  => $after,
            'optimized'    => $after < $before,
        ];
    }

    /**
     * Verifica si una ruta es un archivo legible.
     *
     * @param string $path Ruta del archivo a verificar.
     *
     * @return bool `true` si es un archivo legible, `false` en caso contrario.
     */
    private function isReadableFile(string $path): bool
    {
        return $path !== '' && is_file($path) && is_readable($path);
    }

    /**
     * Detecta el tipo MIME de un archivo usando la extensión `fileinfo`.
     *
     * @param string $fullPath Ruta completa del archivo.
     *
     * @return string Tipo MIME detectado, o 'application/octet-stream' si no se puede determinar.
     */
    private function detectMime(string $fullPath): string
    {
        try {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($fullPath);
            return \is_string($mime) ? $mime : 'application/octet-stream';
        } catch (\Throwable) {
            return 'application/octet-stream';
        }
    }
}