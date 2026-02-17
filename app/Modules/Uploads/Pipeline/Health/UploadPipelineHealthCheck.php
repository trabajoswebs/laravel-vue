<?php

declare(strict_types=1);

namespace App\Modules\Uploads\Pipeline\Health;

use App\Infrastructure\Uploads\Profiles\AvatarProfile;
use App\Infrastructure\Uploads\Pipeline\Scanning\YaraRuleManager;
use App\Infrastructure\Uploads\Pipeline\Security\Exceptions\InvalidRuleException;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ejecuta comprobaciones básicas sobre los componentes críticos del pipeline de subida.
 */
final class UploadPipelineHealthCheck
{
    public function __construct(
        private readonly FilesystemManager $filesystems,
        private readonly QueueFactory $queues,
        private readonly AvatarProfile $avatarProfile,
        private readonly ?YaraRuleManager $yaraRules = null,
    ) {
    }

    /**
     * @return array<string,array{ok:bool,detail?:string}>
     */
    public function run(): array
    {
        return [
            'quarantine' => $this->checkQuarantineWritable(),
            'clamav' => $this->checkClamAvResponsive(),
            'yara' => $this->checkYaraRulesValid(),
            'storage' => $this->checkStorageAvailable(),
            'queue' => $this->checkQueueWorking(),
        ];
    }

    /**
     * Verifica que el disco de cuarentena permita escrituras temporales.
     */
    public function checkQuarantineWritable(): array
    {
        $diskName = (string) config('media.quarantine.disk', 'quarantine');

        try {
            $disk = $this->filesystems->disk($diskName);
            $this->probeDisk($disk, 'upload-health');

            return ['ok' => true, 'detail' => "disk:{$diskName}"];
        } catch (Throwable $exception) {
            return ['ok' => false, 'detail' => $exception->getMessage()];
        }
    }

    /**
     * Comprueba que el binario configurado de ClamAV existe y es ejecutable.
     */
    public function checkClamAvResponsive(): array
    {
        $config = (array) config('image-pipeline.scan.clamav', []);
        $binary = (string) ($config['binary'] ?? '');

        if ($binary === '' || ! is_file($binary)) {
            return ['ok' => false, 'detail' => 'binary_missing'];
        }

        if (! is_executable($binary)) {
            return ['ok' => false, 'detail' => 'binary_not_executable'];
        }

        return ['ok' => true, 'detail' => basename($binary)];
    }

    /**
     * Ejecuta validación de reglas YARA.
     */
    public function checkYaraRulesValid(): array
    {
        if ($this->yaraRules === null) {
            return ['ok' => false, 'detail' => 'manager_unavailable'];
        }

        try {
            $this->yaraRules->validateIntegrity();

            return [
                'ok' => true,
                'detail' => $this->yaraRules->getCurrentVersion(),
            ];
        } catch (InvalidRuleException $exception) {
            return ['ok' => false, 'detail' => $exception->getMessage()];
        }
    }

    /**
     * Comprueba que el disco donde se guardan los avatares puede escribir.
     */
    public function checkStorageAvailable(): array
    {
        $diskName = $this->avatarProfile->disk() ?? config('filesystems.default', 'local');

        try {
            $disk = $this->filesystems->disk($diskName);
            $this->probeDisk($disk, 'avatar-health');

            return ['ok' => true, 'detail' => "disk:{$diskName}"];
        } catch (Throwable $exception) {
            return ['ok' => false, 'detail' => $exception->getMessage()];
        }
    }

    /**
     * Comprueba que la conexión de queue por defecto es accesible.
     */
    public function checkQueueWorking(): array
    {
        $connection = (string) config('queue.default', 'sync');

        try {
            $queue = $this->queues->connection($connection);
            // Invocamos size() para forzar la conexión.
            $queue->size();

            return ['ok' => true, 'detail' => $connection];
        } catch (Throwable $exception) {
            return ['ok' => false, 'detail' => $exception->getMessage()];
        }
    }

    private function probeDisk(FilesystemAdapter $disk, string $prefix): void
    {
        $probe = trim($prefix, '/') . '_check_' . Str::random(10);
        $path = "health/{$probe}.txt";

        $disk->put($path, 'ok', ['visibility' => 'private']);
        $disk->delete($path);
    }
}
