<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Optimizer\Adapters;

use App\Infrastructure\Media\Optimizer\Adapters\RemoteDownloader;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class RemoteDownloaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('tmp');
        Http::preventStrayRequests();
    }

    public function test_downloads_http_file_with_valid_head(): void
    {
        $disk = Storage::disk('tmp');
        $downloader = new RemoteDownloader($disk);
        $tempPath = tempnam(sys_get_temp_dir(), 'rd_http_');
        if ($tempPath === false) {
            $this->fail('Failed to create temporary file');
        }

        $url = 'http://93.184.216.34/image.jpg';

        Http::fake(function (Request $request) use ($url) {
            if ($request->url() === $url && $request->method() === 'HEAD') {
                return Http::response('', 200, [
                    'Content-Type' => 'image/jpeg; charset=UTF-8',
                    'Content-Length' => '4',
                ]);
            }

            if ($request->url() === $url && $request->method() === 'GET') {
                return Http::response('test', 200, [
                    'Content-Type' => 'image/jpeg',
                    'Content-Length' => '4',
                ]);
            }

            return Http::response('', 500);
        });

        $bytes = $downloader->download($url, $tempPath, 4);

        $this->assertSame(4, $bytes);
        $this->assertSame('test', file_get_contents($tempPath));

        @unlink($tempPath);
    }

    public function test_rejects_http_when_content_length_exceeds_limit(): void
    {
        $disk = Storage::disk('tmp');
        $downloader = new RemoteDownloader($disk);
        $tempPath = tempnam(sys_get_temp_dir(), 'rd_http_large_');
        if ($tempPath === false) {
            $this->fail('Failed to create temporary file');
        }

        $url = 'http://93.184.216.34/large.bin';
        $limit = 25 * 1024 * 1024;

        Http::fake(function (Request $request) use ($url, $limit) {
            if ($request->url() === $url && $request->method() === 'HEAD') {
                return Http::response('', 200, [
                    'Content-Type' => 'image/jpeg',
                    'Content-Length' => (string) ($limit + 1),
                ]);
            }

            return Http::response('', 500);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('http_head_length_exceeds_limit');

        try {
            $downloader->download($url, $tempPath, $limit + 1);
        } finally {
            @unlink($tempPath);
        }
    }

    public function test_blocks_private_ip_addresses(): void
    {
        $disk = Storage::disk('tmp');
        $downloader = new RemoteDownloader($disk);
        $tempPath = tempnam(sys_get_temp_dir(), 'rd_http_private_');
        if ($tempPath === false) {
            $this->fail('Failed to create temporary file');
        }

        $url = 'http://127.0.0.1/secret';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dns_ip_blocked');

        try {
            $downloader->download($url, $tempPath, 100);
        } finally {
            @unlink($tempPath);
        }
    }

    public function test_rejects_urls_with_credentials(): void
    {
        $disk = Storage::disk('tmp');
        $downloader = new RemoteDownloader($disk);
        $tempPath = tempnam(sys_get_temp_dir(), 'rd_http_creds_');
        if ($tempPath === false) {
            $this->fail('Failed to create temporary file');
        }

        $url = 'http://user:pass@93.184.216.34/image.jpg';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('http_url_credentials_not_allowed');

        try {
            $downloader->download($url, $tempPath, 100);
        } finally {
            @unlink($tempPath);
        }
    }

    public function test_rejects_disallowed_port(): void
    {
        $disk = Storage::disk('tmp');
        $downloader = new RemoteDownloader($disk);
        $tempPath = tempnam(sys_get_temp_dir(), 'rd_http_port_');
        if ($tempPath === false) {
            $this->fail('Failed to create temporary file');
        }

        $url = 'http://93.184.216.34:8080/image.jpg';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('http_port_not_allowed');

        try {
            $downloader->download($url, $tempPath, 100);
        } finally {
            @unlink($tempPath);
        }
    }

    public function test_keeps_filesystem_flow_for_disk_paths(): void
    {
        $disk = Storage::fake('remotefs');
        $disk->put('images/photo.jpg', 'abc');

        $adapter = Storage::disk('remotefs');
        $downloader = new RemoteDownloader($adapter);
        $tempPath = tempnam(sys_get_temp_dir(), 'rd_disk_');
        if ($tempPath === false) {
            $this->fail('Failed to create temporary file');
        }

        $bytes = $downloader->download('images/photo.jpg', $tempPath, 3);

        $this->assertSame(3, $bytes);
        $this->assertSame('abc', file_get_contents($tempPath));

        @unlink($tempPath);
    }

    public function test_internal_guard_blocks_ipv4_mapped_ipv6(): void
    {
        $disk = Storage::disk('tmp');
        $downloader = new RemoteDownloader($disk);

        $reflection = new \ReflectionClass($downloader);
        $method = $reflection->getMethod('assertIpAllowed');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dns_ip_blocked');

        $method->invoke($downloader, '::ffff:127.0.0.1');
    }
}
