<?php

/**
 * Archivo: app/Infrastructure/Uploads/Pipeline/Support/MediaArtifactCollector.php
 * Objetivo: versión mejorada con logging PSR-3, flags de config cacheadas,
 *           filtros por tipo de artefacto y más contexto en logs.
 * Notas: comentarios + ejemplos donde aplican.
 */

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Support; // Ej.: "App\Infrastructure\Uploads"

use App\Infrastructure\Uploads\Core\Contracts\MediaArtifactCollector as MediaArtifactCollectorContract;
use App\Infrastructure\Uploads\Core\Contracts\MediaOwner; // Interfaz que expone getMedia() // Ej.: $owner->getMedia('avatar') => Collection<Media>
use Illuminate\Contracts\Filesystem\Factory as StorageFactory; // Acceso a discos Laravel // Ej.: app('filesystem')->disk('s3')
use Illuminate\Filesystem\FilesystemAdapter; // Adapter de Laravel (envoltorio de Flysystem) // Ej.: Storage::disk('s3') devuelve FilesystemAdapter
use League\Flysystem\FilesystemOperator; // Driver nativo Flysystem v3 // Ej.: $adapter->getDriver()
use Psr\Log\LoggerInterface; // PSR-3 logger // Ej.: $logger->info('msg')
use Psr\Log\LogLevel; // Niveles estándar PSR-3 // Ej.: LogLevel::DEBUG
use App\Infrastructure\Uploads\Core\Adapters\SpatieMediaResource;
use Spatie\MediaLibrary\MediaCollections\Models\Media; // Modelo Media de Spatie // Ej.: Media::find(1)
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator; // Generador de rutas v10/v11 // Ej.: $paths->getPath($media)

/**
 * Recopila rutas y metadatos de artefactos asociados a medios:
 * - Archivo original
 * - Conversiones (imágenes generadas)
 * - Imágenes responsivas
 *
 * Resultados agrupados por disco y con verificación opcional de existencia.
 *
 * ✨ Mejoras clave:
 * - Logging PSR-3 con ->log() (evita métodos dinámicos)
 * - Flags de configuración cacheadas por llamada (menos lecturas de config())
 * - Más contexto en logs (collection, model_type, model_id, conv_disk)
 * - Filtros por tipo de artefacto (original/conversions/responsive)
 * - Opción para asumir existencia por disco (p.ej., S3) vía config
 */
final class MediaArtifactCollector implements MediaArtifactCollectorContract // Final: evita herencia accidental
{
    // Tipos de artefactos soportados
    private const TYPE_ORIGINAL    = 'original';    // Directorio del original // Ej.: "1/2"
    private const TYPE_CONVERSIONS = 'conversions'; // Directorio de conversiones // Ej.: "1/2/conversions"
    private const TYPE_RESPONSIVE  = 'responsive';  // Directorio de responsive // Ej.: "1/2/responsive"

    /** @var array<int,string> */
    private const ALL_TYPES = [
        self::TYPE_ORIGINAL,
        self::TYPE_CONVERSIONS,
        self::TYPE_RESPONSIVE,
    ];

    /** @var array<int,string> Lista de drivers remotos conocidos. */
    private const REMOTE_DRIVERS = [
        's3',
        's3-compatible',
        'minio',
        'digitalocean',
        'spaces',
        'sftp',
        'ftp',
    ];

    public function __construct(
        private readonly PathGenerator   $paths,   // Genera rutas de medios (v10/v11) // Ej.: $paths->getPath($media) => "1/2"
        private readonly StorageFactory  $storage, // Acceso a discos de Laravel // Ej.: $this->storage->disk('s3')
        private readonly LoggerInterface $logger,  // Logger PSR-3 // Ej.: $this->logger->info('...')
    ) {}

    /**
     * Recopila rutas de artefactos existentes agrupados por disco.
     *
     * @param MediaOwner       $owner      Dueño que expone getMedia($collection) (p.ej., User)
     * @param string           $collection Nombre de colección Spatie (p.ej., 'avatar')
     * @param array<int,string> $types     Tipos a inspeccionar (default: original+conversions+responsive)
     *
     * @return array<int, array{
     *   media: Media,
     *   artifacts: array<string, list<string>>  // disco => [ruta1, ruta2, ...]
     * }>
     *
     * Ej. salida:
     * [
     *   [
     *     'media' => Media{id:1,...},
     *     'artifacts' => [
     *        'public' => ['1/2'],
     *        'media'  => ['1/2/conversions','1/2/responsive']
     *     ]
     *   ]
     * ]
     */
    public function collect(MediaOwner $owner, string $collection, array $types = []): array
    {
        // Lee flags una sola vez
        [$shouldCheck, $logMissingAsDebug, $assumeExistForDisks] = $this->configFlags(); // Ej.: [true,true,['s3']]

        $items = []; // Resultado
        $mediaItems = $owner->getMedia($collection); // Collection<Media> // Ej.: $user->getMedia('avatar')
        $types = $types === [] ? self::ALL_TYPES : $types;

        foreach ($mediaItems as $media) {
            $items[] = [
                'media'     => new SpatieMediaResource($media),
                'artifacts' => $this->directoriesMapForMedia(
                    $media,
                    $types,
                    $shouldCheck,
                    $logMissingAsDebug,
                    $assumeExistForDisks
                ),
            ];
        }

        return $items;
    }

    /**
     * Recopila rutas de artefactos con metadatos detallados por disco/tipo.
     *
     * @param array<int,string> $types
     * @return array<int, array{
     *   media: Media,
     *   disks: array<string, array{
     *     original: array{path: ?string, exists: bool},
     *     conversions: array{path: ?string, exists: bool},
     *     responsive: array{path: ?string, exists: bool},
     *   }>
     * }>
     */
    public function collectDetailed(MediaOwner $owner, string $collection, array $types = self::ALL_TYPES): array
    {
        [$shouldCheck, $logMissingAsDebug, $assumeExistForDisks] = $this->configFlags();

        $items = [];
        $mediaItems = $owner->getMedia($collection);

        foreach ($mediaItems as $media) {
            $items[] = [
                'media' => new SpatieMediaResource($media),
                'disks' => $this->directoriesDetailedForMedia(
                    $media,
                    $types,
                    $shouldCheck,
                    $logMissingAsDebug,
                    $assumeExistForDisks
                ),
            ];
        }

        return $items;
    }

    /**
     * Genera un mapa disco => [rutas existentes] limitado por $types.
     *
     * @param array<int,string> $types
     * @param array<int,string> $assumeExistForDisks
     * @return array<string, list<string>>
     */
    private function directoriesMapForMedia(
        Media $media,
        array $types,
        bool $shouldCheck,
        bool $logMissingAsDebug,
        array $assumeExistForDisks
    ): array {
        $map = [];

        // Original: en disco principal (si está solicitado)
        if (in_array(self::TYPE_ORIGINAL, $types, true)) {
            $this->addDirectoryIfExists(
                $map,
                $media,
                $media->disk,
                self::TYPE_ORIGINAL,
                $shouldCheck,
                $logMissingAsDebug,
                $assumeExistForDisks
            );
        }

        // Conversiones y responsive: en disco de conversiones
        $conversionDisk = $this->getConversionDisk($media);

        if (in_array(self::TYPE_CONVERSIONS, $types, true)) {
            $this->addDirectoryIfExists(
                $map,
                $media,
                $conversionDisk,
                self::TYPE_CONVERSIONS,
                $shouldCheck,
                $logMissingAsDebug,
                $assumeExistForDisks
            );
        }

        if (in_array(self::TYPE_RESPONSIVE, $types, true)) {
            $this->addDirectoryIfExists(
                $map,
                $media,
                $conversionDisk,
                self::TYPE_RESPONSIVE,
                $shouldCheck,
                $logMissingAsDebug,
                $assumeExistForDisks
            );
        }

        // Limpia: elimina duplicados y valores vacíos
        return array_map(
            static fn(array $paths): array => array_values(array_unique(array_filter($paths))),
            $map
        );
    }

    /**
     * Genera estructura detallada con estado de existencia por tipo, limitada por $types.
     *
     * @param array<int,string> $types
     * @param array<int,string> $assumeExistForDisks
     * @return array<string, array{
     *   original: array{path: ?string, exists: bool},
     *   conversions: array{path: ?string, exists: bool},
     *   responsive: array{path: ?string, exists: bool}
     * }>
     */
    private function directoriesDetailedForMedia(
        Media $media,
        array $types,
        bool $shouldCheck,
        bool $logMissingAsDebug, // (no se usa aquí directamente, pero queda simétrico por si lo añades)
        array $assumeExistForDisks
    ): array {
        $result = [];

        // Disco principal: original
        if (in_array(self::TYPE_ORIGINAL, $types, true)) {
            $result[$media->disk]['original'] = $this->resolveDirectory(
                $media,
                $media->disk,
                self::TYPE_ORIGINAL,
                $shouldCheck,
                $assumeExistForDisks
            );
        }

        // Disco de conversiones: conversions + responsive
        $conversionDisk = $this->getConversionDisk($media);

        if (in_array(self::TYPE_CONVERSIONS, $types, true)) {
            $result[$conversionDisk]['conversions'] = $this->resolveDirectory(
                $media,
                $conversionDisk,
                self::TYPE_CONVERSIONS,
                $shouldCheck,
                $assumeExistForDisks
            );
        }

        if (in_array(self::TYPE_RESPONSIVE, $types, true)) {
            $result[$conversionDisk]['responsive'] = $this->resolveDirectory(
                $media,
                $conversionDisk,
                self::TYPE_RESPONSIVE,
                $shouldCheck,
                $assumeExistForDisks
            );
        }

        return $result;
    }

    /**
     * Añade una ruta al mapa solo si existe físicamente (o si se asume existencia) y está habilitada.
     *
     * @param array<string, list<string>> $map Referencia al mapa de salida
     * @param array<int,string> $assumeExistForDisks Discos donde asumimos existencia
     */
    private function addDirectoryIfExists(
        array &$map,
        Media $media,
        string $disk,
        string $type,
        bool $shouldCheck,
        bool $logMissingAsDebug,
        array $assumeExistForDisks
    ): void {
        $resolved = $this->resolveDirectory(
            $media,
            $disk,
            $type,
            $shouldCheck,
            $assumeExistForDisks
        );

        if ($resolved['exists'] && is_string($resolved['path']) && $resolved['path'] !== '') {
            $map[$disk][] = $resolved['path'];
            return;
        }

        // Log informativo si hay una ruta válida pero no existe (y está habilitada la verificación)
        if ($shouldCheck && $resolved['path'] !== null) {
            $level = $logMissingAsDebug ? LogLevel::DEBUG : LogLevel::INFO;
            $this->logger->log($level, 'media.artifacts.missing_directory', [
                'media_id'   => $media->id ?? null,
                'disk'       => $disk,
                'type'       => $type,
                'path'       => $resolved['path'],
                'collection' => $media->collection_name ?? null,
                'model_type' => $media->model_type ?? null,
                'model_id'   => $media->model_id ?? null,
                'conv_disk'  => $media->conversions_disk ?? null,
            ]);
        }
    }

    /**
     * Resuelve la ruta de un artefacto y verifica su existencia según flags.
     *
     * @param array<int,string> $assumeExistForDisks
     * @return array{path: ?string, exists: bool}
     * Ej.: ['path' => '1/2/conversions', 'exists' => true]
     */
    private function resolveDirectory(
        Media $media,
        string $disk,
        string $type,
        bool $shouldCheck,
        array $assumeExistForDisks
    ): array {
        try {
            // Genera la ruta según el tipo de artefacto
            $directory = match ($type) {
                self::TYPE_ORIGINAL    => $this->paths->getPath($media),                  // Ej.: '1/2'
                self::TYPE_CONVERSIONS => $this->paths->getPathForConversions($media),   // Ej.: '1/2/conversions'
                self::TYPE_RESPONSIVE  => $this->paths->getPathForResponsiveImages($media), // Ej.: '1/2/responsive'
                default                => '',
            };

            $normalized = $this->normalize($directory); // Normaliza slashes // Ej.: '1/2/conversions'

            if ($normalized === '') {
                return ['path' => null, 'exists' => false];
            }

            // Si la verificación está deshabilitada o el disco está en la lista de "asumidos", asumimos existencia
            if (!$shouldCheck || in_array($disk, $assumeExistForDisks, true)) {
                return ['path' => $normalized, 'exists' => true];
            }

            // Verifica si el directorio existe en el disco
            $exists = $this->directoryExists($disk, $normalized, $media, $type);

            return ['path' => $normalized, 'exists' => $exists];
        } catch (\Throwable $e) {
            // Log de advertencia y retorno seguro en caso de error
            $this->logger->warning('media.artifacts.resolve_failed', [
                'media_id'   => $media->id ?? null,
                'disk'       => $disk,
                'type'       => $type,
                'error'      => $e->getMessage(),
                'collection' => $media->collection_name ?? null,
                'model_type' => $media->model_type ?? null,
                'model_id'   => $media->model_id ?? null,
            ]);

            return ['path' => null, 'exists' => false];
        }
    }

    /**
     * Verifica si un directorio existe en un disco específico.
     * Compatible con diferentes versiones de Flysystem.
     */
    private function directoryExists(string $disk, string $path, Media $media, string $type): bool
    {
        /** @var FilesystemAdapter $fs */
        $fs = $this->storage->disk($disk); // Ej.: FilesystemAdapter(s3)

        // Intenta usar el método directo del adapter (Laravel >= 9)
        if (method_exists($fs, 'directoryExists')) {
            return $fs->directoryExists($path); // true/false
        }

        // Si no, intenta usar el driver interno de Flysystem
        /** @var FilesystemOperator $driver */
        $driver = $fs->getDriver();

        if (method_exists($driver, 'directoryExists')) {
            return $driver->directoryExists($path); // true/false
        }

        if ($this->isRemoteDisk($disk)) {
            return $this->remoteDirectoryExists($fs, $disk, $path, $media, $type);
        }

        // Si no se puede verificar, log de advertencia y asume inexistencia
        $this->logger->warning('media.artifacts.driver_no_directoryexists', [
            'media_id' => $media->id ?? null,
            'disk'     => $disk,
            'type'     => $type,
            'path'     => $path,
        ]);

        return false; // Conservador
    }

    private function remoteDirectoryExists(
        FilesystemAdapter $fs,
        string $disk,
        string $path,
        Media $media,
        string $type
    ): bool {
        $filesCount = $this->safeCount(
            fn () => $fs->files($path, false),
            $disk,
            $path,
            $media,
            $type,
            'files'
        );
        $directoriesCount = $this->safeCount(
            fn () => $fs->directories($path, false),
            $disk,
            $path,
            $media,
            $type,
            'directories'
        );

        if ($filesCount !== null || $directoriesCount !== null) {
            $found = (int) max(0, $filesCount ?? 0) + (int) max(0, $directoriesCount ?? 0);

            $this->logger->debug('media.artifacts.remote_probe', [
                'media_id'    => $media->id ?? null,
                'disk'        => $disk,
                'type'        => $type,
                'path'        => $path,
                'files'       => $filesCount,
                'directories' => $directoriesCount,
                'exists'      => $found > 0,
            ]);

            return $found > 0;
        }

        $this->logger->notice('media.artifacts.remote_directory_assumed', [
            'media_id' => $media->id ?? null,
            'disk'     => $disk,
            'type'     => $type,
            'path'     => $path,
        ]);

        return true;
    }

    private function safeCount(
        callable $callback,
        string $disk,
        string $path,
        Media $media,
        string $type,
        string $probe
    ): ?int {
        try {
            $result = $callback();
            if (is_array($result)) {
                return count($result);
            }
            if ($result instanceof \Traversable) {
                return iterator_count($result);
            }
        } catch (\Throwable $e) {
            $this->logger->debug('media.artifacts.remote_probe_failed', [
                'media_id' => $media->id ?? null,
                'disk'     => $disk,
                'type'     => $type,
                'path'     => $path,
                'probe'    => $probe,
                'error'    => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function isRemoteDisk(string $disk): bool
    {
        $driver = config("filesystems.disks.{$disk}.driver");

        if (!is_string($driver) || $driver === '') {
            return false;
        }

        return in_array(strtolower($driver), self::REMOTE_DRIVERS, true);
    }

    /**
     * Obtiene el disco configurado para conversiones (o el disco original si no hay uno específico).
     */
    private function getConversionDisk(Media $media): string
    {
        return $media->conversions_disk ?: $media->disk; // Fallback
    }

    /**
     * Normaliza una ruta eliminando caracteres redundantes.
     */
    private function normalize(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/'); // Ej.: "\\1/2//" => "1/2"
    }

    /**
     * Lee y empaqueta flags de configuración evitando lecturas repetidas.
     *
     * @return array{0:bool,1:bool,2:array<int,string>} [shouldCheck, logMissingAsDebug, assumeExistForDisks]
     */
    private function configFlags(): array
    {
        $shouldCheck = (bool) config('media-collector.check_exists', true); // Ej.: true
        $logMissingAsDebug = (bool) config('media-collector.log_missing_as_debug', true); // Ej.: true
        $assumeExistForDisks = (array) config('media-collector.assume_exist_for_disks', []); // Ej.: ['s3']
        return [$shouldCheck, $logMissingAsDebug, $assumeExistForDisks];
    }
}
