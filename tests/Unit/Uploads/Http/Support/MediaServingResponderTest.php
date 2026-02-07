<?php

declare(strict_types=1);

namespace Tests\Unit\Uploads\Http\Support;

use App\Infrastructure\Uploads\Http\Support\MediaServingResponder;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TemporaryUrlFilesystem;
use Tests\TestCase;

/**
 * Pruebas unitarias para la clase MediaServingResponder.
 * 
 * Esta clase prueba la funcionalidad de respuesta HTTP para servir archivos multimedia
 * tanto desde discos locales como desde servicios como S3.
 */
final class MediaServingResponderTest extends TestCase
{
    /**
     * Prueba que la respuesta de disco local use cache control privado desde la configuración.
     * 
     * Esta prueba verifica que cuando se sirve un archivo desde un disco local:
     * - Devuelve un código de estado 200
     * - Usa headers de cache control apropiados (private, max-age configurado, must-revalidate)
     * - Incluye header de seguridad X-Content-Type-Options
     */
    public function test_local_disk_response_uses_private_cache_control_from_config(): void
    {
        // Configura un disco local falso con un archivo de avatar
        Storage::fake('local');
        Storage::disk('local')->put('tenants/1/users/1/avatars/avatar.jpg', 'img');
        
        // Establece la configuración de tiempo de cache para discos locales
        config()->set('media-serving.local_max_age_seconds', 120);

        // Ejecuta el método de servicio para servir el archivo
        $response = (new MediaServingResponder())->serve('local', 'tenants/1/users/1/avatars/avatar.jpg');

        // Verifica que la respuesta tenga el código de estado correcto
        self::assertSame(200, $response->getStatusCode());
        
        // Verifica que los headers de cache control contengan los valores esperados
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('max-age=120', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('must-revalidate', (string) $response->headers->get('Cache-Control'));
        
        // Verifica que el header de seguridad esté presente
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /**
     * Prueba que la respuesta de disco S3 redireccione con cache control no almacenado.
     * 
     * Esta prueba verifica que cuando se sirve un archivo desde un disco S3:
     * - Devuelve un código de estado 302 (redirección)
     * - Usa headers de cache control apropiados para URLs temporales (no-store, max-age=0)
     * - Incluye header de seguridad X-Content-Type-Options
     */
    public function test_s3_disk_response_redirects_with_no_store_cache_control(): void
    {
        // Configura el driver de disco S3 y el tiempo de vida de URLs temporales
        config()->set('filesystems.disks.s3.driver', 's3');
        config()->set('media-serving.s3_temporary_url_ttl_seconds', 300);

        // Crea un adaptador falso que simula un filesystem con URL temporal
        $adapter = new TemporaryUrlFilesystem('https://s3.test/media  ', ['tenants/1/users/1/avatars/avatar.jpg']);
        
        // Mockea la llamada a Storage::disk para devolver nuestro adaptador
        Storage::shouldReceive('disk')->once()->with('s3')->andReturn($adapter);

        // Ejecuta el método de servicio para servir el archivo
        $response = (new MediaServingResponder())->serve('s3', 'tenants/1/users/1/avatars/avatar.jpg');

        // Verifica que la respuesta sea una redirección (302)
        self::assertSame(302, $response->getStatusCode());
        
        // Verifica que los headers de cache control contengan los valores esperados para URLs temporales
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('max-age=0', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        
        // Verifica que el header de seguridad esté presente
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }
}