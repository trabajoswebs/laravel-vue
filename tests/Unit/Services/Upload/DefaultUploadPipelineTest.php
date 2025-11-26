<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Upload;

// Importamos las clases necesarias para los tests
use App\Infrastructure\Media\Upload\DefaultUploadPipeline; // Pipeline de subida por defecto
use App\Infrastructure\Media\Upload\Exceptions\UploadValidationException; // Excepción para validación de subida
use Tests\TestCase; // Clase base para tests de Laravel

final class DefaultUploadPipelineTest extends TestCase
{
    /**
     * Array para almacenar directorios temporales creados durante los tests.
     * Se usará para limpiarlos en tearDown().
     *
     * @var list<string>
     */
    private array $tempDirectories = [];

    /**
     * Método que se ejecuta después de cada test para limpiar recursos.
     */
    protected function tearDown(): void
    {
        // Eliminamos todos los directorios temporales creados durante los tests
        foreach ($this->tempDirectories as $directory) {
            $this->deleteDirectory($directory);
        }

        // Llamamos al tearDown padre para limpieza adicional
        parent::tearDown();
    }

    /**
     * Test que verifica que el pipeline lanza una excepción cuando el archivo fuente no es legible.
     */
    public function testProcessThrowsWhenSourceIsNotReadable(): void
    {
        // Creamos una instancia del pipeline con configuración por defecto
        $pipeline = $this->makePipeline();

        // Esperamos que se lance una UploadValidationException
        $this->expectException(UploadValidationException::class);

        // Intentamos procesar un archivo que no existe
        $pipeline->process('/path/to/missing/file.jpg');
    }

    /**
     * Test que verifica que el pipeline rechaza payloads sospechosos.
     */
    public function testProcessRejectsSuspiciousPayloads(): void
    {
        // Creamos un pipeline que permite text/plain y txt
        $pipeline = $this->makePipeline(['text/plain'], ['txt']);
        // Creamos un archivo temporal con contenido sospechoso
        $path = $this->createTempFile('txt', "<script>alert('xss')</script>");

        try {
            // Esperamos que se lance una excepción al procesar el archivo
            $this->expectException(UploadValidationException::class);
            $pipeline->process($path);
        } finally {
            // Aseguramos que eliminamos el archivo temporal
            @unlink($path);
        }
    }

    /**
     * Verifica que las firmas sospechosas se detectan incluso si están divididas entre chunks.
     */
    public function testProcessDetectsPayloadAcrossChunkBoundary(): void
    {
        $pipeline = $this->makePipeline(['text/plain'], ['txt']);
        $chunkSize = (int) (new \ReflectionClass(DefaultUploadPipeline::class))->getConstant('CHUNK_SIZE');
        $prefix = str_repeat('A', $chunkSize - 2);
        $payload = $prefix . '<s' . 'cript>';
        $path = $this->createTempFile('txt', $payload);

        try {
            $this->expectException(UploadValidationException::class);
            $pipeline->process($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Crea una instancia del pipeline de subida con configuración específica.
     *
     * @param array<int, string> $allowedMimes MIMEs permitidos
     * @param array<int, string> $allowedExtensions Extensiones permitidas
     * @return DefaultUploadPipeline Instancia del pipeline
     */
    private function makePipeline(
        array $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'], // MIMEs por defecto
        array $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'], // Extensiones por defecto
    ): DefaultUploadPipeline {
        // Creamos un directorio temporal único para este test
        $workingDirectory = sys_get_temp_dir() . '/upload-pipeline-tests/' . uniqid('', true);

        // Creamos el directorio con permisos 0775 y creación recursiva
        if (!is_dir($workingDirectory) && !@mkdir($workingDirectory, 0775, true) && !is_dir($workingDirectory)) {
            $this->fail('Unable to create working directory for tests.');
        }

        // Añadimos el directorio a la lista para limpiarlo después
        $this->tempDirectories[] = $workingDirectory;

        // Creamos y devolvemos una instancia del pipeline
        return new DefaultUploadPipeline(
            $workingDirectory, // Directorio de trabajo
            $allowedMimes, // MIMEs permitidos
            $allowedExtensions, // Extensiones permitidas
            512 * 1024 // Tamaño máximo (512KB)
        );
    }

    /**
     * Crea un archivo temporal con contenido específico.
     *
     * @param string $extension Extensión del archivo
     * @param string $contents Contenido del archivo
     * @return string Ruta del archivo temporal creado
     */
    private function createTempFile(string $extension, string $contents): string
    {
        // Creamos una ruta única para el archivo temporal
        $path = sys_get_temp_dir() . '/upload-source-' . uniqid('', true) . '.' . ltrim($extension, '.');

        // Escribimos el contenido en el archivo
        if (file_put_contents($path, $contents) === false) {
            $this->fail('Unable to prepare temporary upload file.');
        }

        return $path;
    }

    /**
     * Elimina recursivamente un directorio y todo su contenido.
     *
     * @param string $directory Directorio a eliminar
     */
    private function deleteDirectory(string $directory): void
    {
        // Si no es un directorio, no hacemos nada
        if (!is_dir($directory)) {
            return;
        }

        // Creamos un iterador recursivo para recorrer todo el directorio
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), // Omitir . y ..
            \RecursiveIteratorIterator::CHILD_FIRST // Procesar hijos antes que padres
        );

        // Iteramos sobre todos los archivos y directorios
        foreach ($iterator as $fileInfo) {
            $path = $fileInfo->getRealPath();
            if ($path === false) {
                continue; // Saltar si no podemos obtener la ruta real
            }

            if ($fileInfo->isDir()) {
                // Si es directorio, lo eliminamos con rmdir
                @rmdir($path);
            } else {
                // Si es archivo, lo eliminamos con unlink
                @unlink($path);
            }
        }

        // Finalmente eliminamos el directorio raíz
        @rmdir($directory);
    }
}
