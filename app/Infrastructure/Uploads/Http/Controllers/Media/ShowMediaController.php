<?php // Controlador para servir medios autenticados y tenant-aware sin storage:link

declare(strict_types=1); // Fuerza tipado estricto

namespace App\Infrastructure\Uploads\Http\Controllers\Media; // Mantiene la convención de Media controllers // Ej: rutas /media

use App\Application\Shared\Contracts\TenantContextInterface; // Permite obtener tenant actual // Ej: app(TenantContextInterface)
use App\Infrastructure\Uploads\Http\Support\MediaServingResponder;
use App\Infrastructure\Uploads\Pipeline\Security\Logging\MediaSecurityLogger;
use Illuminate\Contracts\Filesystem\Filesystem; // Tipo para discos de Storage // Ej: FilesystemAdapter
use Illuminate\Http\Request; // Request HTTP entrante // Ej: path capturado
use Illuminate\Routing\Controller; // Base Controller de Laravel // Ej: para inyección
use Illuminate\Support\Facades\Storage; // Acceso a discos // Ej: Storage::disk('local')
use Symfony\Component\HttpFoundation\Response; // Respuesta base HttpFoundation // Ej: para tipos unificados

final class ShowMediaController extends Controller // Controlador invocable // Ej: Route::get('/media/{path}', ShowMediaController::class)
{
    /**
     * Constructor que inyecta el contexto de tenant
     * 
     * @param TenantContextInterface $tenantContext Servicio para obtener tenant actual
     */
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly MediaServingResponder $responder,
        private readonly MediaSecurityLogger $securityLogger,
    ) // Inyecta contexto tenant // Ej: tenantId()
    {
    }

    /**
     * Maneja la solicitud GET para mostrar un archivo de media
     * Implementa validación de tenant, seguridad de paths y manejo de discos
     * 
     * @param Request $request Solicitud HTTP entrante
     * @param string $path Ruta del archivo dentro del sistema de archivos
     * @return Response Respuesta con el archivo
     */
    public function __invoke(Request $request, string $path): Response // Maneja GET /media/{path} // Ej: /media/tenants/1/users/2/avatar.jpg
    {
        $tenantId = $this->tenantContext->tenantId(); // Lee tenant actual desde contexto // Ej: 1
        $userId = $request->user()?->getAuthIdentifier(); // Id del usuario autenticado

        if ($tenantId === null) { // Si no hay tenant // Ej: sesión inconsistente
            $this->logDeniedAccess('tenant_missing', '', $tenantId, $userId); // Loguea fallo sin path
            abort(404); // Oculta detalles de denegación
        }

        $cleanPath = $this->sanitizePath($path, $tenantId, $userId); // Normaliza y limpia traversal // Ej: tenants/1/users/2/avatar.jpg

        $prefix = 'tenants/' . $tenantId . '/'; // Prefijo esperado por seguridad // Ej: tenants/1/

        if (! str_starts_with($cleanPath, $prefix)) { // Si el path no pertenece al tenant // Ej: tenants/999/
            $this->logDeniedAccess('wrong_tenant_prefix', $cleanPath, $tenantId, $userId); // Loguea prefijo inválido
            abort(404); // Responde 404 para no filtrar
        }

        if (! $this->isAllowedPath($cleanPath, $tenantId)) { // Aplica allowlist de rutas servibles // Evita servidor genérico
            $this->logDeniedAccess('not_in_allowlist', $cleanPath, $tenantId, $userId); // Loguea rechazo de allowlist
            abort(404); // Responde 404 para no filtrar existencia // Ej: tenants/1/secret.txt
        }

        $disk = $this->resolveDisk($cleanPath); // Determina disk donde buscar // Ej: avatars

        $adapter = Storage::disk($disk); // Obtiene Filesystem para disk elegido // Ej: Storage::disk('avatars')

        if (! $adapter->exists($cleanPath)) { // Verifica que el archivo exista // Ej: conversión aún no lista
            $fallback = $this->tryOriginalFallback($adapter, $cleanPath, $disk); // Intenta servir original si falta conversión // Ej: devuelve path sin conversions
            if ($fallback !== null) { // Si hay fallback // Ej: sirve original
                $cleanPath = $fallback; // Usa path de fallback // Ej: tenants/1/.../v123.jpg
            } else { // Si no hay fallback // Ej: intentará otros discos
                [$altDisk, $altPath] = $this->findOnAlternateDisks($cleanPath, $disk); // Busca en otros discos permitidos // Ej: public vs avatars

                if ($altDisk !== null && $altPath !== null) { // Si encontró en otro disk // Ej: usa public
                    $disk = $altDisk; // Cambia disk // Ej: public
                    $adapter = Storage::disk($disk); // Reobtiene adapter // Ej: Storage::disk('public')
                    $cleanPath = $altPath; // Ajusta path // Ej: tenants/.../v123.jpg
                } else { // No se halló en ningún sitio // Ej: 404
                    abort(404, 'Media no encontrado'); // Devuelve 404 // Ej: evita filtrar info
                }
            }
        }

        return $this->responder->serve($disk, $cleanPath); // Delega headers y temporalUrl en helper común
    }

    /**
     * Limpia y normaliza el path recibido para prevenir ataques de traversal
     * 
     * @param string $path Path original del parámetro de ruta
     * @return string Path limpio y seguro
     */
    private function sanitizePath(string $path, int|string $tenantId, int|string|null $userId): string // Limpia path recibido del route param // Ej: quita .. y backslashes
    {
        $decoded = rawurldecode($path); // Acepta rutas con %2F sin doble decode // Ej: tenants%2F1 -> tenants/1
        $normalized = str_replace('\\', '/', $decoded); // Normaliza separador // Ej: tenants\\1 -> tenants/1
        $segments = array_filter(explode('/', $normalized), static fn($segment) => $segment !== '' && $segment !== '.'); // Elimina vacíos/puntos // Ej: ['tenants','1','img.jpg']

        if (in_array('..', $segments, true)) { // Detecta traversal // Ej: tenants/1/../../env
            $this->logDeniedAccess('path_traversal', $normalized, $tenantId, $userId); // Loguea intento de traversal
            abort(404); // Oculta existencia // Ej: 404
        }

        return implode('/', $segments); // Reconstruye path limpio // Ej: tenants/1/users/2/avatar.jpg
    }

    /**
     * Determina el disco adecuado donde buscar el archivo
     * Prueba discos en orden de prioridad hasta encontrar uno donde exista el archivo
     * 
     * @param string $cleanPath Path limpio del archivo
     * @return string Nombre del disco donde se encontró el archivo
     */
    private function resolveDisk(string $cleanPath): string // Elige disk apropiado para servir media // Ej: avatars/public
    {
        $candidates = array_values(array_filter([ // Lista de discos priorizados // Ej: ['avatars','public','local']
            config('image-pipeline.avatar_disk'), // Disco configurado para avatares // Ej: avatars
            config('media-library.conversions_disk'), // Disco opcional para conversiones // Ej: media-conversions
            config('media-library.disk_name'), // Disco por defecto de Media Library // Ej: public
            config('filesystems.default'), // Disco default del framework // Ej: local
            config('filesystems.cloud'), // Disco cloud si está configurado // Ej: s3
        ], static fn($disk) => is_string($disk) && trim($disk) !== '')); // Filtra valores inválidos // Ej: sólo strings

        $unique = array_values(array_unique($candidates)); // Quita duplicados manteniendo orden // Ej: ['avatars','public','local']

        foreach ($unique as $disk) { // Itera discos candidatos // Ej: avatars primero
            $config = config("filesystems.disks.{$disk}"); // Lee config del disk // Ej: ['driver'=>'local',...]

            if (! is_array($config)) { // Si el disk no existe // Ej: typo
                continue; // Salta // Ej: sigue al siguiente
            }

            $adapter = Storage::disk($disk); // Obtiene FilesystemAdapter // Ej: Storage::disk('avatars')

            if ($adapter instanceof Filesystem && $adapter->exists($cleanPath)) { // Usa primer disk donde exista el archivo // Ej: true
                return $disk; // Devuelve disk válido // Ej: avatars
            }
        }

        return $unique[0] ?? config('filesystems.default', 'local'); // Fallback al primer candidato o default // Ej: local
    }

    /**
     * Intenta encontrar el archivo original si no se encuentra una conversión
     * Útil cuando se solicita una conversión que aún no ha sido generada
     * 
     * @param Filesystem $adapter Filesystem donde buscar
     * @param string $cleanPath Path de la conversión solicitada
     * @param string|null $disk Nombre del disco actual
     * @return string|null Path del archivo original encontrado, o null si no se encuentra
     */
    private function tryOriginalFallback(Filesystem $adapter, string $cleanPath, ?string $disk = null): ?string // Fallback si falta conversión // Ej: sirve original
    {
        if (! str_contains($cleanPath, '/conversions/')) { // Solo aplica a conversions faltantes // Ej: original ya 404
            return null; // Sin fallback // Ej: devolvemos null
        }

        if ($this->isRemoteDisk($adapter, $disk)) { // Evita listados en S3 u otros remotos // Ej: no usar files()
            return null; // No intentamos fallback remoto // Ej: devolvemos null
        }

        $originalDir = str_replace('/conversions/', '/', $cleanPath); // Mueve del subdir conversions al base // Ej: tenants/.../avatars/uuid/v123-thumb.webp -> tenants/.../avatars/uuid/v123-thumb.webp
        $filename = basename($originalDir); // Nombre de archivo con conversión // Ej: v123-thumb.webp
        $dir = trim(str_replace($filename, '', $originalDir), '/'); // Directorio base sin conversions // Ej: tenants/.../avatars/uuid

        $base = preg_replace('/-[^-]+\\.[^.]+$/', '', $filename); // Quita sufijo de conversión + extensión // Ej: v123-thumb.webp -> v123

        // Busca cualquier archivo que comience igual (cubre .jpg/.png/.webp)
        $candidates = $adapter->files($dir); // Lista archivos en directorio base // Ej: tenants/.../v123.jpg
        foreach ($candidates as $candidate) { // Itera archivos // Ej: tenants/.../v123.jpg
            if (str_contains($candidate, $base)) { // Coincide prefijo // Ej: true
                return $candidate; // Usa ese archivo como fallback // Ej: sirve original
            }
        }

        return null; // No se encontró fallback // Ej: null
    }

    /**
     * Busca el archivo en otros discos configurados cuando el actual no lo tiene.
     * 
     * @param string $cleanPath Path del archivo a buscar
     * @param string $currentDisk Disco actual donde no se encontró
     * @return array Array con [disk|null, path|null] del archivo encontrado
     */
    private function findOnAlternateDisks(string $cleanPath, string $currentDisk): array // Devuelve [disk|null, path|null] // Ej: ['public','tenants/.../v123.jpg']
    {
        $candidates = array_values(array_filter([
            config('image-pipeline.avatar_disk'),
            config('media-library.conversions_disk'),
            config('media-library.disk_name'),
            config('filesystems.default'),
            config('filesystems.cloud'),
        ], static fn($disk) => is_string($disk) && trim($disk) !== '')); // Reutiliza lista de discos incluyendo conversions y cloud

        foreach ($candidates as $disk) { // Itera discos posibles // Ej: public
            if ($disk === $currentDisk) { // Omite el actual // Ej: evita repetir
                continue; // Continúa // Ej: siguiente disk
            }

            $adapter = Storage::disk($disk); // Obtiene adapter del disk // Ej: Storage::disk('public')

            if ($adapter instanceof Filesystem && $adapter->exists($cleanPath)) { // Si existe mismo path // Ej: true
                return [$disk, $cleanPath]; // Devuelve hallazgo // Ej: ['public', cleanPath]
            }

            // Si no existe la conversión, intenta fallback al original en este disk.
            $fallback = $this->tryOriginalFallback($adapter, $cleanPath, $disk); // Busca original en este disk // Ej: tenants/.../v123.jpg
            if ($fallback !== null) { // Si se encuentra fallback // Ej: original existe
                return [$disk, $fallback]; // Devuelve disk+path // Ej: ['public', fallback]
            }
        }

        return [null, null]; // Ningún disco tiene el archivo // Ej: devolvemos nulls
    }

    /**
     * Valida si el path está permitido según la configuración de allowlist
     * 
     * @param string $cleanPath Path limpio a validar
     * @param int|string $tenantId ID del tenant actual
     * @return bool True si el path está permitido, false en caso contrario
     */
    private function isAllowedPath(string $cleanPath, int|string $tenantId): bool // Valida contra allowlist configurable // Ej: avatares
    {
        $allowed = config('media-serving.allowed_paths', []); // Lista de prefijos permitidos // Ej: config/media-serving.php

        foreach ($allowed as $entry) { // Itera patrones // Ej: tenants/{tenantId}/users/{userId}/avatars/
            $pattern = is_array($entry) ? ($entry['pattern'] ?? '') : (string) $entry; // Soporta string o array pattern
            if ($pattern === '') { // Skip vacíos
                continue;
            }

            $regex = $this->compileAllowRegex($pattern, $tenantId); // Convierte patrón a regex // Ej: #^tenants/1/users/[0-9]+/avatars/.*$#
            if (preg_match($regex, $cleanPath) === 1) { // Coincide inicio // Ej: true
                return true; // Path permitido
            }
        }

        return false; // Ningún patrón coincide // Ej: bloqueado
    }

    /**
     * Compila un patrón de allowlist en una expresión regular segura
     * Reemplaza placeholders como {tenantId}, {userId} por sus valores o patrones adecuados
     * 
     * @param string $pattern Patrón de allowlist con placeholders
     * @param int|string $tenantId ID del tenant actual
     * @return string Expresión regular compilada
     */
    private function compileAllowRegex(string $pattern, int|string $tenantId): string // Convierte patrón con placeholders a regex seguro // Ej: tenants/{tenantId}/...
    {
        $escaped = preg_quote($pattern, '#'); // Escapa regex // Ej: tenants/\{tenantId\}/users/\{userId\}/avatars/

        $escaped = str_replace(
            ['\{tenantId\}', '\{userId\}', '\*'],
            [preg_quote((string) $tenantId, '#'), '[0-9]+', '[^/]+'],
            $escaped
        ); // Reemplaza placeholders por valores/regex // Ej: tenants/1/users/[0-9]+/avatars/

        return '#^' . rtrim($escaped, '\/') . '/?.*$#'; // Regex de prefijo // Ej: ^tenants/1/users/[0-9]+/avatars/.*$
    }

    /**
     * Determina si el disco es remoto (no local)
     * 
     * @param Filesystem $adapter Instancia del filesystem
     * @param string|null $disk Nombre del disco (opcional)
     * @return bool True si es disco remoto, false si es local
     */
    private function isRemoteDisk(Filesystem $adapter, ?string $disk): bool // Determina si el disk es remoto (no-local)
    {
        $driver = $disk !== null ? (string) config("filesystems.disks.{$disk}.driver", 'local') : 'local'; // Driver configurado // Ej: s3

        return $driver !== 'local'; // Considera remoto todo lo que no sea driver local
    }

    /**
     * Registra un evento de denegación de forma discreta (nivel debug o canal dedicado).
     *
     * @param string $reason   Motivo corto de la denegación
     * @param string $path     Path normalizado (no se guarda en claro; se hashea)
     * @param int|string|null $tenantId Tenant asociado si existe
     * @param int|string|null $userId   Usuario autenticado si existe
     */
    private function logDeniedAccess(string $reason, string $path, int|string|null $tenantId, int|string|null $userId): void
    {
        $payload = [
            'reason'    => $reason,
            'tenant_id' => $tenantId,
            'user_id'   => $userId,
            'path'      => $path,
        ];

        $this->securityLogger->warning('media.security.denied', $payload);
    }
}
