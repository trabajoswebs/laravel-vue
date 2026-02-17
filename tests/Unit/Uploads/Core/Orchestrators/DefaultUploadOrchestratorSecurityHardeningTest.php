<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads\Core\Orchestrators;

use App\Support\Contracts\TenantContextInterface;
use App\Application\Uploads\Contracts\UploadRepositoryInterface;
use App\Application\Uploads\Contracts\UploadStorageInterface;
use App\Support\Enums\Uploads\ProcessingMode;
use App\Support\Enums\Uploads\ScanMode;
use App\Support\Enums\Uploads\ServingMode;
use App\Support\Enums\Uploads\UploadKind;
use App\Domain\Uploads\UploadProfile;
use App\Domain\Uploads\UploadProfileId;
use App\Modules\Uploads\Contracts\MediaArtifactCollector;
use App\Modules\Uploads\Contracts\MediaCleanupScheduler;
use App\Modules\Uploads\Contracts\MediaUploader;
use App\Infrastructure\Uploads\Core\Orchestrators\DefaultUploadOrchestrator;
use App\Infrastructure\Uploads\Core\Orchestrators\DocumentUploadGuard;
use App\Infrastructure\Uploads\Core\Orchestrators\MediaProfileResolver;
use App\Modules\Uploads\Paths\TenantPathGenerator;
use App\Modules\Uploads\Services\MediaReplacementService;
use App\Modules\Uploads\Pipeline\Quarantine\QuarantineRepository;
use App\Modules\Uploads\Pipeline\Scanning\ScanCoordinatorInterface;
use App\Infrastructure\Uploads\Pipeline\Support\PipelineResultMapper;
use App\Infrastructure\Uploads\Pipeline\Support\QuarantineManager;
use App\Infrastructure\Uploads\Profiles\AvatarProfile;
use App\Infrastructure\Uploads\Profiles\GalleryProfile;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class DefaultUploadOrchestratorSecurityHardeningTest extends TestCase
{
    public function test_validate_document_does_not_trust_client_mime_for_imports(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $method = new \ReflectionMethod($orchestrator, 'validateDocument');
        $method->setAccessible(true);

        $path = tempnam(sys_get_temp_dir(), 'upload_');
        self::assertIsString($path);
        file_put_contents($path, "\x00\x01\x02\x03\x04\x05\x06\x07");

        $uploaded = new UploadedFile($path, 'evil.csv', 'text/plain', null, true);
        $profile = new UploadProfile(
            id: new UploadProfileId('import_csv'),
            kind: UploadKind::DOCUMENT,
            allowedMimes: ['text/csv', 'text/plain'],
            maxBytes: 1024 * 1024,
            scanMode: ScanMode::REQUIRED,
            processingMode: ProcessingMode::NONE,
            servingMode: ServingMode::CONTROLLER_SIGNED,
            disk: 'public',
            pathCategory: 'imports',
            requiresOwner: false,
        );

        try {
            $this->expectException(InvalidArgumentException::class);
            $method->invoke($orchestrator, $profile, $uploaded);
        } finally {
            @unlink($path);
        }
    }

    public function test_validate_document_rejects_secret_payload_without_pkcs12_signature(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $method = new \ReflectionMethod($orchestrator, 'validateDocument');
        $method->setAccessible(true);

        $path = tempnam(sys_get_temp_dir(), 'upload_secret_');
        self::assertIsString($path);
        file_put_contents($path, "\x01\x02\x03\x04" . random_bytes(64));

        $uploaded = new UploadedFile($path, 'cert.p12', 'application/octet-stream', null, true);
        $profile = new UploadProfile(
            id: new UploadProfileId('certificate_secret'),
            kind: UploadKind::SECRET,
            allowedMimes: ['application/x-pkcs12', 'application/octet-stream'],
            maxBytes: 1024 * 1024,
            scanMode: ScanMode::REQUIRED,
            processingMode: ProcessingMode::NONE,
            servingMode: ServingMode::FORBIDDEN,
            disk: 'public',
            pathCategory: 'secrets',
            requiresOwner: false,
        );

        try {
            $this->expectException(InvalidArgumentException::class);
            $method->invoke($orchestrator, $profile, $uploaded);
        } finally {
            @unlink($path);
        }
    }

    public function test_validate_document_accepts_secret_payload_with_pkcs12_like_signature(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $method = new \ReflectionMethod($orchestrator, 'validateDocument');
        $method->setAccessible(true);

        $path = tempnam(sys_get_temp_dir(), 'upload_secret_ok_');
        self::assertIsString($path);
        file_put_contents($path, "\x30\x82\x04\x00" . random_bytes(64));

        $uploaded = new UploadedFile($path, 'cert.p12', 'application/octet-stream', null, true);
        $profile = new UploadProfile(
            id: new UploadProfileId('certificate_secret'),
            kind: UploadKind::SECRET,
            allowedMimes: ['application/x-pkcs12', 'application/octet-stream'],
            maxBytes: 1024 * 1024,
            scanMode: ScanMode::REQUIRED,
            processingMode: ProcessingMode::NONE,
            servingMode: ServingMode::FORBIDDEN,
            disk: 'public',
            pathCategory: 'secrets',
            requiresOwner: false,
        );

        try {
            $method->invoke($orchestrator, $profile, $uploaded);
            $this->assertTrue(true);
        } finally {
            @unlink($path);
        }
    }

    public function test_resolve_mime_for_secrets_falls_back_to_allowed_uploaded_mime_when_trusted_mime_is_not_allowed(): void
    {
        $guard = new DocumentUploadGuard();
        $method = new \ReflectionMethod($guard, 'resolveMime');
        $method->setAccessible(true);

        $path = tempnam(sys_get_temp_dir(), 'upload_secret_mime_fallback_');
        self::assertIsString($path);
        file_put_contents($path, "plain text content\n");

        $uploaded = new class($path) extends UploadedFile {
            public function __construct(string $path)
            {
                parent::__construct($path, 'cert.p12', 'application/octet-stream', null, true);
            }

            public function getMimeType(): ?string
            {
                return 'application/octet-stream';
            }
        };

        $profile = new UploadProfile(
            id: new UploadProfileId('certificate_secret'),
            kind: UploadKind::SECRET,
            allowedMimes: ['application/x-pkcs12', 'application/octet-stream'],
            maxBytes: 1024 * 1024,
            scanMode: ScanMode::REQUIRED,
            processingMode: ProcessingMode::NONE,
            servingMode: ServingMode::FORBIDDEN,
            disk: 'public',
            pathCategory: 'secrets',
            requiresOwner: false,
        );

        try {
            $mime = $method->invoke($guard, $profile, $uploaded, $path);
            $this->assertSame('application/octet-stream', $mime);
        } finally {
            @unlink($path);
        }
    }

    public function test_validate_document_accepts_single_column_import_csv(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $method = new \ReflectionMethod($orchestrator, 'validateDocument');
        $method->setAccessible(true);

        $path = tempnam(sys_get_temp_dir(), 'upload_csv_single_col_');
        self::assertIsString($path);
        file_put_contents($path, "email\nalice@example.com\nbob@example.com\n");

        $uploaded = new UploadedFile($path, 'import.csv', 'text/csv', null, true);
        $profile = new UploadProfile(
            id: new UploadProfileId('import_csv'),
            kind: UploadKind::IMPORT,
            allowedMimes: ['text/csv', 'text/plain', 'application/csv', 'text/x-csv'],
            maxBytes: 1024 * 1024,
            scanMode: ScanMode::REQUIRED,
            processingMode: ProcessingMode::NONE,
            servingMode: ServingMode::FORBIDDEN,
            disk: 'public',
            pathCategory: 'imports',
            requiresOwner: false,
        );

        try {
            $method->invoke($orchestrator, $profile, $uploaded);
            $this->assertTrue(true);
        } finally {
            @unlink($path);
        }
    }

    public function test_validate_document_accepts_import_with_atypical_header_row(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $method = new \ReflectionMethod($orchestrator, 'validateDocument');
        $method->setAccessible(true);

        $path = tempnam(sys_get_temp_dir(), 'upload_csv_header_');
        self::assertIsString($path);
        file_put_contents($path, "___header___\n<script literal text>\nplain text\n");

        $uploaded = new UploadedFile($path, 'import.csv', 'text/plain', null, true);
        $profile = new UploadProfile(
            id: new UploadProfileId('import_csv'),
            kind: UploadKind::IMPORT,
            allowedMimes: ['text/csv', 'text/plain', 'application/csv', 'text/x-csv'],
            maxBytes: 1024 * 1024,
            scanMode: ScanMode::REQUIRED,
            processingMode: ProcessingMode::NONE,
            servingMode: ServingMode::FORBIDDEN,
            disk: 'public',
            pathCategory: 'imports',
            requiresOwner: false,
        );

        try {
            $method->invoke($orchestrator, $profile, $uploaded);
            $this->assertTrue(true);
        } finally {
            @unlink($path);
        }
    }

    public function test_validate_document_accepts_import_with_multiline_quoted_cells(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $method = new \ReflectionMethod($orchestrator, 'validateDocument');
        $method->setAccessible(true);

        $path = tempnam(sys_get_temp_dir(), 'upload_csv_multiline_');
        self::assertIsString($path);
        file_put_contents($path, "id,notes\n1,\"line one\nline two\"\n2,\"ok\"\n");

        $uploaded = new UploadedFile($path, 'import.csv', 'text/csv', null, true);
        $profile = new UploadProfile(
            id: new UploadProfileId('import_csv'),
            kind: UploadKind::IMPORT,
            allowedMimes: ['text/csv', 'text/plain', 'application/csv', 'text/x-csv'],
            maxBytes: 1024 * 1024,
            scanMode: ScanMode::REQUIRED,
            processingMode: ProcessingMode::NONE,
            servingMode: ServingMode::FORBIDDEN,
            disk: 'public',
            pathCategory: 'imports',
            requiresOwner: false,
        );

        try {
            $method->invoke($orchestrator, $profile, $uploaded);
            $this->assertTrue(true);
        } finally {
            @unlink($path);
        }
    }

    public function test_validate_document_accepts_utf16le_import_payload(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $method = new \ReflectionMethod($orchestrator, 'validateDocument');
        $method->setAccessible(true);

        $path = tempnam(sys_get_temp_dir(), 'upload_csv_utf16_');
        self::assertIsString($path);

        $utf16Body = iconv('UTF-8', 'UTF-16LE//IGNORE', "email\nalice@example.com\n");
        if (!is_string($utf16Body)) {
            $this->markTestSkipped('iconv no está disponible en este entorno.');
        }
        file_put_contents($path, "\xFF\xFE" . $utf16Body);

        $uploaded = new UploadedFile($path, 'import.csv', 'text/plain', null, true);
        $profile = new UploadProfile(
            id: new UploadProfileId('import_csv'),
            kind: UploadKind::IMPORT,
            allowedMimes: ['text/csv', 'text/plain', 'application/csv', 'text/x-csv'],
            maxBytes: 1024 * 1024,
            scanMode: ScanMode::REQUIRED,
            processingMode: ProcessingMode::NONE,
            servingMode: ServingMode::FORBIDDEN,
            disk: 'public',
            pathCategory: 'imports',
            requiresOwner: false,
        );

        try {
            $method->invoke($orchestrator, $profile, $uploaded);
            $this->assertTrue(true);
        } finally {
            @unlink($path);
        }
    }

    public function test_validate_document_rejects_import_payload_starting_with_php_tag(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $method = new \ReflectionMethod($orchestrator, 'validateDocument');
        $method->setAccessible(true);

        $path = tempnam(sys_get_temp_dir(), 'upload_csv_php_tag_');
        self::assertIsString($path);
        file_put_contents($path, "<?php echo 'x';\n1,2\n");

        $uploaded = new UploadedFile($path, 'import.csv', 'text/plain', null, true);
        $profile = new UploadProfile(
            id: new UploadProfileId('import_csv'),
            kind: UploadKind::IMPORT,
            allowedMimes: ['text/csv', 'text/plain', 'application/csv', 'text/x-csv'],
            maxBytes: 1024 * 1024,
            scanMode: ScanMode::REQUIRED,
            processingMode: ProcessingMode::NONE,
            servingMode: ServingMode::FORBIDDEN,
            disk: 'public',
            pathCategory: 'imports',
            requiresOwner: false,
        );

        try {
            $this->expectException(InvalidArgumentException::class);
            $method->invoke($orchestrator, $profile, $uploaded);
        } finally {
            @unlink($path);
        }
    }

    public function test_validate_document_rejects_secret_payload_with_weak_der_prefix(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $method = new \ReflectionMethod($orchestrator, 'validateDocument');
        $method->setAccessible(true);

        $path = tempnam(sys_get_temp_dir(), 'upload_secret_weak_');
        self::assertIsString($path);
        file_put_contents($path, "\x30\x01\x02\x03" . random_bytes(64));

        $uploaded = new UploadedFile($path, 'cert.p12', 'application/octet-stream', null, true);
        $profile = new UploadProfile(
            id: new UploadProfileId('certificate_secret'),
            kind: UploadKind::SECRET,
            allowedMimes: ['application/x-pkcs12', 'application/octet-stream'],
            maxBytes: 1024 * 1024,
            scanMode: ScanMode::REQUIRED,
            processingMode: ProcessingMode::NONE,
            servingMode: ServingMode::FORBIDDEN,
            disk: 'public',
            pathCategory: 'secrets',
            requiresOwner: false,
        );

        try {
            $this->expectException(InvalidArgumentException::class);
            $method->invoke($orchestrator, $profile, $uploaded);
        } finally {
            @unlink($path);
        }
    }

    public function test_resolve_tenant_first_media_path_rejects_non_tenant_paths(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $method = new \ReflectionMethod($orchestrator, 'resolveTenantFirstMediaPath');
        $method->setAccessible(true);

        $media = new class {
            public function getPathRelativeToRoot(): string
            {
                return 'users/5/avatars/file.jpg';
            }
        };

        $this->expectException(RuntimeException::class);
        $method->invoke($orchestrator, $media, 1);
    }

    public function test_resolve_tenant_first_media_path_accepts_tenant_prefixed_paths(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $method = new \ReflectionMethod($orchestrator, 'resolveTenantFirstMediaPath');
        $method->setAccessible(true);

        $media = new class {
            public function getPathRelativeToRoot(): string
            {
                return 'tenants/1/users/5/avatars/file.jpg';
            }
        };

        $clean = $method->invoke($orchestrator, $media, 1);
        $this->assertSame('tenants/1/users/5/avatars/file.jpg', $clean);
    }

    private function makeOrchestrator(): DefaultUploadOrchestrator
    {
        $quarantine = new QuarantineManager($this->createMock(QuarantineRepository::class));

        return new DefaultUploadOrchestrator(
            $this->createMock(TenantContextInterface::class),
            $this->createMock(TenantPathGenerator::class),
            $quarantine,
            $this->createMock(ScanCoordinatorInterface::class),
            $this->createMock(UploadRepositoryInterface::class),
            $this->createMock(UploadStorageInterface::class),
            new MediaReplacementService(
                $this->createMock(MediaUploader::class),
                $this->createMock(MediaArtifactCollector::class),
                $this->createMock(MediaCleanupScheduler::class),
            ),
            new PipelineResultMapper(),
            new MediaProfileResolver(new AvatarProfile(), new GalleryProfile()),
            new DocumentUploadGuard(),
        );
    }
}
