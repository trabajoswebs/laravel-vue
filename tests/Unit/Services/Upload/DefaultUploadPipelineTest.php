<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Upload;

// Importamos las clases necesarias para los tests
use App\Modules\Uploads\Pipeline\DefaultUploadPipeline; // Pipeline de subida por defecto
use App\Modules\Uploads\Pipeline\Exceptions\UploadValidationException; // Excepción para validación de subida
use App\Modules\Uploads\Pipeline\Exceptions\VirusDetectedException;
use App\Modules\Uploads\Pipeline\ImageUploadPipelineAdapter;
use App\Infrastructure\Uploads\Pipeline\Security\MagicBytesValidator;
use App\Modules\Uploads\Contracts\FileConstraints;
use App\Modules\Uploads\Contracts\MediaProfile;
use App\Support\Contracts\MetricsInterface;
use App\Support\Contracts\LoggerInterface;
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
     * Copia de valores originales de configuración mutados durante las pruebas.
     *
     * @var array<string,mixed>
     */
    private array $originalConfig = [];

    /**
     * Método que se ejecuta después de cada test para limpiar recursos.
     */
    protected function tearDown(): void
    {
        // Restauramos configuración modificada durante las pruebas
        foreach ($this->originalConfig as $key => $value) {
            config()->set($key, $value);
        }
        $this->originalConfig = [];

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
        [$pipeline, $profile] = $this->makePipeline();

        // Esperamos que se lance una UploadValidationException
        $this->expectException(UploadValidationException::class);

        // Intentamos procesar un archivo que no existe
        $pipeline->process('/path/to/missing/file.jpg', $profile, 'test-correlation');
    }

    /**
     * Test que verifica que el pipeline rechaza payloads sospechosos.
     */
    public function testProcessRejectsSuspiciousPayloads(): void
    {
        // Creamos un pipeline que permite text/plain y txt
        [$pipeline, $profile] = $this->makePipeline(['text/plain'], ['txt']);
        // Creamos un archivo temporal con contenido sospechoso
        $path = $this->createTempFile('txt', "<script>alert('xss')</script>");

        try {
            // Esperamos que se lance una excepción al procesar el archivo
            $this->expectException(UploadValidationException::class);
            $pipeline->process($path, $profile, 'test-correlation');
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
        [$pipeline, $profile] = $this->makePipeline(['text/plain'], ['txt']);
        $chunkSize = (int) (new \ReflectionClass(DefaultUploadPipeline::class))->getConstant('CHUNK_SIZE');
        $prefix = str_repeat('A', $chunkSize - 2);
        $payload = $prefix . '<s' . 'cript>';
        $path = $this->createTempFile('txt', $payload);

        try {
            $this->expectException(UploadValidationException::class);
            $pipeline->process($path, $profile, 'test-correlation');
        } finally {
            @unlink($path);
        }
    }

    public function testProcessPreservesVirusDetectedExceptionFromImagePipeline(): void
    {
        $workingDirectory = sys_get_temp_dir() . '/upload-pipeline-tests/' . uniqid('', true);
        if (!is_dir($workingDirectory) && !@mkdir($workingDirectory, 0775, true) && !is_dir($workingDirectory)) {
            $this->fail('Unable to create working directory for tests.');
        }
        $this->tempDirectories[] = $workingDirectory;

        $securityLogger = new NullMagicLogger();
        $magicBytes = new MagicBytesValidator($securityLogger);
        $metrics = $this->createMock(MetricsInterface::class);
        $metrics->method('increment')->willReturnCallback(static function (): void {});
        $metrics->method('timing')->willReturnCallback(static function (): void {});

        $imagePipeline = $this->createMock(ImageUploadPipelineAdapter::class);
        $imagePipeline->method('process')->willThrowException(new VirusDetectedException('blocked'));

        $constraints = $this->constraints(['image/jpeg'], ['jpg'], 512 * 1024);
        $profile = new class($constraints) implements MediaProfile {
            public function __construct(private FileConstraints $constraints) {}
            public function collection(): string { return 'test'; }
            public function disk(): ?string { return null; }
            public function conversions(): array { return []; }
            public function isSingleFile(): bool { return true; }
            public function fileConstraints(): FileConstraints { return $this->constraints; }
            public function fieldName(): string { return 'file'; }
            public function requiresSquare(): bool { return false; }
            public function applyConversions(\App\Modules\Uploads\Contracts\MediaOwner $model, ?\Spatie\MediaLibrary\MediaCollections\Models\Media $media = null): void {}
            public function usesQuarantine(): bool { return false; }
            public function usesAntivirus(): bool { return false; }
            public function requiresImageNormalization(): bool { return true; }
            public function getQuarantineTtlHours(): int { return 0; }
            public function getFailedTtlHours(): int { return 0; }
        };

        $pipeline = new DefaultUploadPipeline(
            $workingDirectory,
            $imagePipeline,
            $magicBytes,
            $securityLogger,
            $metrics
        );

        $path = $this->createTempFile('jpg', 'fake-image-content');

        try {
            $this->expectException(VirusDetectedException::class);
            $pipeline->process($path, $profile, 'test-correlation');
        } finally {
            @unlink($path);
        }
    }

    public function testProcessUsesImmutableSnapshotWhenSourceChangesAfterValidation(): void
    {
        $this->rememberOriginalConfig(['image-pipeline.min_dimension']);
        config()->set('image-pipeline.min_dimension', 1);

        $original = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO6pH1kAAAAASUVORK5CYII=');
        self::assertIsString($original);
        $mutated = 'MUTATED_CONTENT';
        $sourcePath = $this->createTempFile('png', $original);
        $sourceHash = hash('sha256', $original) ?: '';
        [$pipeline, $profile] = $this->makePipeline(['image/png'], ['png']);

        $flippingSource = new class($sourcePath, $mutated) extends \SplFileObject {
            private int $rewindCount = 0;
            private bool $mutated = false;

            public function __construct(string $path, private readonly string $mutatedContent)
            {
                parent::__construct($path, 'rb');
            }

            public function rewind(): void
            {
                parent::rewind();
                $this->rewindCount++;

                // Mutación controlada tras finalizar la copia inmutable del source.
                if (!$this->mutated && $this->rewindCount >= 3) {
                    $realPath = $this->getRealPath();
                    if (is_string($realPath) && $realPath !== '') {
                        file_put_contents($realPath, $this->mutatedContent);
                        $this->mutated = true;
                    }
                    parent::rewind();
                }
            }
        };

        try {
            $result = $pipeline->process($flippingSource, $profile, 'test-correlation');

            $this->assertSame($original, file_get_contents($result->path));
            $this->assertSame($sourceHash, $result->metadata->hash);
            $this->assertSame($mutated, file_get_contents($sourcePath));
        } finally {
            @unlink($sourcePath);
            if (isset($result) && is_string($result->path)) {
                @unlink($result->path);
            }
        }
    }

    /**
     * Crea una instancia del pipeline de subida con configuración específica.
     * 
     * @param array<int, string> $allowedMimes MIMEs permitidos
     * @param array<int, string> $allowedExtensions Extensiones permitidas
     * @return array{DefaultUploadPipeline, MediaProfile} Instancia del pipeline y perfil
     */
    private function makePipeline(
        array $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'], // MIMEs por defecto
        array $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'], // Extensiones por defecto
        int $maxBytes = 512 * 1024, // Tamaño máximo por defecto (512KB)
    ): array {
        // Creamos un directorio temporal único para este test
        $workingDirectory = sys_get_temp_dir() . '/upload-pipeline-tests/' . uniqid('', true);

        // Creamos el directorio con permisos 0775 y creación recursiva
        if (!is_dir($workingDirectory) && !@mkdir($workingDirectory, 0775, true) && !is_dir($workingDirectory)) {
            $this->fail('Unable to create working directory for tests.');
        }

        // Añadimos el directorio a la lista para limpiarlo después
        $this->tempDirectories[] = $workingDirectory;

        $securityLogger = new NullMagicLogger();
        $magicBytes = new MagicBytesValidator($securityLogger);
        $metrics = $this->createMock(MetricsInterface::class);
        $metrics->method('increment')->willReturnCallback(static function (): void {});
        $metrics->method('timing')->willReturnCallback(static function (): void {});

        $imagePipeline = $this->createMock(ImageUploadPipelineAdapter::class);

        $constraints = $this->constraints($allowedMimes, $allowedExtensions, $maxBytes);
        $profile = new class($constraints) implements MediaProfile {
            public function __construct(private FileConstraints $constraints) {}
            public function collection(): string { return 'test'; }
            public function disk(): ?string { return null; }
            public function conversions(): array { return []; }
            public function isSingleFile(): bool { return true; }
            public function fileConstraints(): FileConstraints { return $this->constraints; }
            public function fieldName(): string { return 'file'; }
            public function requiresSquare(): bool { return false; }
            public function applyConversions(\App\Modules\Uploads\Contracts\MediaOwner $model, ?\Spatie\MediaLibrary\MediaCollections\Models\Media $media = null): void {}
            public function usesQuarantine(): bool { return false; }
            public function usesAntivirus(): bool { return false; }
            public function requiresImageNormalization(): bool { return false; }
            public function getQuarantineTtlHours(): int { return 0; }
            public function getFailedTtlHours(): int { return 0; }
        };

        // Creamos y devolvemos una instancia del pipeline junto con el perfil
        return [
            new DefaultUploadPipeline(
                $workingDirectory, // Directorio de trabajo
                $imagePipeline,
                $magicBytes,
                $securityLogger,
                $metrics
            ),
            $profile,
        ];
    }

    /**
     * Construye restricciones de archivos para las pruebas.
     *
     * @param array<int,string> $allowedMimes
     * @param array<int,string> $allowedExtensions
     */
    private function constraints(array $allowedMimes, array $allowedExtensions, int $maxBytes): FileConstraints
    {
        $this->rememberOriginalConfig([
            'image-pipeline.allowed_mimes',
            'image-pipeline.allowed_extensions',
            'image-pipeline.max_bytes',
        ]);

        // Ajustamos configuración temporal para que FileConstraints use los valores solicitados.
        config()->set('image-pipeline.allowed_mimes', $this->buildMimeMap($allowedMimes, $allowedExtensions));
        config()->set('image-pipeline.allowed_extensions', $allowedExtensions);
        config()->set('image-pipeline.max_bytes', $maxBytes);

        return new FileConstraints();
    }

    /**
     * Construye un mapa MIME => extensión usando heurísticas simples.
     *
     * @param array<int,string> $allowedMimes
     * @param array<int,string> $allowedExtensions
     * @return array<string,string>
     */
    private function buildMimeMap(array $allowedMimes, array $allowedExtensions): array
    {
        $extensions = array_values($allowedExtensions);
        $fallback = $extensions[0] ?? 'bin';
        $map = [];

        foreach ($allowedMimes as $mime) {
            $base = strtolower(substr($mime, (int) strrpos($mime, '/') + 1));
            $match = null;

            foreach ($extensions as $extension) {
                $normalized = strtolower($extension);
                if ($normalized === $base || str_starts_with($normalized, $base) || str_starts_with($base, $normalized)) {
                    $match = $extension;
                    break;
                }
            }

            $map[$mime] = $match ?? $fallback;
        }

        return $map;
    }

    /**
     * Guarda una copia de los valores de configuración antes de mutarlos.
     *
     * @param array<int,string> $keys
     */
    private function rememberOriginalConfig(array $keys): void
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $this->originalConfig)) {
                $this->originalConfig[$key] = config($key);
            }
        }
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

final class NullMagicLogger implements LoggerInterface
{
    public function debug(string $message, array $context = []): void {}
    public function info(string $message, array $context = []): void {}
    public function notice(string $message, array $context = []): void {}
    public function warning(string $message, array $context = []): void {}
    public function error(string $message, array $context = []): void {}
    public function critical(string $message, array $context = []): void {}
    public function alert(string $message, array $context = []): void {}
}
