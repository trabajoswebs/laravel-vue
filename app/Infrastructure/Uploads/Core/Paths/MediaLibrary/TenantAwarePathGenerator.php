<?php
/**
 * GENERADOR DE PATHS DE MEDIA LIBRARY CONSCIENTE DE TENANT
 * 
 * Implementa el contrato PathGenerator de Spatie MediaLibrary para generar rutas
 * de almacenamiento que respetan la arquitectura multi-tenant con patrón "tenant-first".
 * 
 * 🎯 OBJETIVO PRINCIPAL:
 *   Aislar completamente los archivos de cada tenant en directorios separados,
 *   evitando mezcla de archivos entre diferentes organizaciones/clientes.
 * 
 * 📁 ESTRUCTURA DE DIRECTORIOS GENERADA:
 *   {tenant_id}/{profile}/{owner_id}/{version}/{unique}.{ext}
 *   
 *   Ejemplo: tenant_abc/avatar_image/123/4567890/550e8400-e29b-41d4-a716-446655440000.jpg
 */

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Core\Paths\MediaLibrary;

use App\Application\Shared\Contracts\TenantContextInterface;
use App\Domain\Uploads\UploadProfileId;
use App\Infrastructure\Models\User;
use App\Infrastructure\Uploads\Core\Paths\TenantPathGenerator;
use App\Infrastructure\Uploads\Core\Paths\TenantPathLayout;
use App\Infrastructure\Uploads\Core\Registry\UploadProfileRegistry;
use InvalidArgumentException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

/**
 * Generador de rutas para Media Library con aislamiento tenant-first.
 * 
 * Esta clase es el corazón del sistema de almacenamiento multi-tenant.
 * Cada archivo se almacena en una ruta que incluye el tenant_id como primer segmento,
 * garantizando aislamiento total y facilitando operaciones como:
 *   - Backup por tenant
 *   - Migración selectiva
 *   - Políticas de retención por organización
 *   - CDN con prefijos por tenant
 * 
 * @implements PathGenerator
 */
final class TenantAwarePathGenerator implements PathGenerator
{
    /**
     * @param TenantContextInterface $tenantContext  Contexto actual del tenant (petición/job)
     * @param TenantPathGenerator    $paths          Generador de paths tenant-first
     * @param UploadProfileRegistry  $profiles       Registro de perfiles de subida
     * @param TenantPathLayout       $layout         Utilidad para extraer partes de rutas
     */
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly TenantPathGenerator $paths,
        private readonly UploadProfileRegistry $profiles,
        private readonly TenantPathLayout $layout,
    ) {
    }

    /**
     * {@inheritDoc}
     * 
     * Genera la ruta base para el archivo original del media.
     * 
     * 🧠 ESTRATEGIA DE GENERACIÓN:
     *   1. Obtiene el perfil de dominio según la colección (avatar_image, gallery_image)
     *   2. Resuelve el tenant_id mediante resolución en cascada:
     *      a) Custom property 'tenant_id' del media (persistido al subir)
     *      b) Tenant del modelo owner (usuario)
     *      c) Tenant del contexto actual
     *      d) Lanza excepción si no hay tenant (requiereTenantId)
     *   3. Normaliza la versión a entero para ordenamiento natural
     *   4. Genera UUID único por subida (upload_uuid o media.uuid)
     *   5. Delega en TenantPathGenerator la construcción del path completo
     *   6. Extrae solo el directorio base (sin filename) para Spatie
     * 
     * 🔐 SEGURIDAD:
     *   Sanitiza el tenant_id para prevenir path traversal (../, caracteres especiales)
     *   Solo permite alfanuméricos, guiones y underscores.
     * 
     * @param Media $media Modelo de Media de Spatie
     * @return string Directorio base con slash final (ej: "tenant_abc/avatar_image/123/456/")
     * 
     * @throws InvalidArgumentException Si el tenant_id es inválido o no existe
     */
    public function getPath(Media $media): string
    {
        // --- 1. OBTENER PERFIL DE DOMINIO ---
        $profile = $this->profileFor($media);
        
        // --- 2. EXTRACCIÓN DE DATOS BASE ---
        $ownerId = $media->model_id;           // ID del modelo relacionado (ej: user_id)
        $tenantId = $this->resolveTenantId($media); // Tenant ID con resolución en cascada
        $ext = $media->extension ?? 'bin';     // Extensión del archivo, fallback seguro
        
        // --- 3. VERSIONADO PARA CACHÉ BUSTING ---
        // La versión permite invalidar cachés de CDN/browser al cambiar el archivo
        // Se normaliza a entero para ordenamiento natural y rendimiento en índices
        $version = $media->getCustomProperty('version') ?? $media->uuid ?? time();
        $versionInt = is_numeric($version) ? (int) $version : crc32((string) $version);
        
        // --- 4. IDENTIFICADOR ÚNICO DE SUBIDA ---
        // upload_uuid es el identificador canónico de la transacción de subida
        // Permite agrupar todos los archivos relacionados a una misma subida
        $unique = $media->getCustomProperty('upload_uuid') ?: $media->uuid;
        
        // --- 5. GENERACIÓN DE RUTA COMPLETA ---
        // TenantPathGenerator construye: {tenant}/{profile}/{ownerId}/{version}/{unique}.{ext}
        $full = $this->paths->generateForTenant(
            $profile, 
            $tenantId, 
            $ownerId, 
            $ext, 
            $versionInt, 
            $unique
        );
        
        // --- 6. EXTRACCIÓN DE DIRECTORIO BASE ---
        // Spatie MediaLibrary espera solo el directorio, no el filename completo
        // Ej: "tenant_abc/avatar_image/123/4567890/" (sin filename)
        return $this->layout->baseDirectory($full);
    }

    /**
     * {@inheritDoc}
     * 
     * Genera la ruta base para las conversiones (thumbnails, recortes, etc.).
     * 
     * 📂 ESTRUCTURA:
     *   {path_base}conversions/
     *   
     *   Ejemplo: tenant_abc/avatar_image/123/4567890/conversions/
     * 
     * Las conversiones se almacenan en un subdirectorio para:
     *   - Organización clara
     *   - Fácil limpieza selectiva (eliminar solo conversiones)
     *   - Políticas de CDN diferenciadas
     * 
     * @param Media $media
     * @return string Directorio base para conversiones con slash final
     */
    public function getPathForConversions(Media $media): string
    {
        return $this->getPath($media) . 'conversions/';
    }

    /**
     * {@inheritDoc}
     * 
     * Genera la ruta base para las responsive images (srcset).
     * 
     * 📂 ESTRUCTURA:
     *   {path_base}responsive-images/
     *   
     *   Ejemplo: tenant_abc/avatar_image/123/4567890/responsive-images/
     * 
     * Separado de conversiones regulares para:
     *   - Claridad semántica
     *   - Diferentes estrategias de generación
     *   - Políticas de caché independientes
     * 
     * @param Media $media
     * @return string Directorio base para responsive images con slash final
     */
    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->getPath($media) . 'responsive-images/';
    }

    /**
     * Determina el perfil de dominio aplicable según la colección del media.
     * 
     * 🔄 MAPEO DE COLECCIÓN A PERFIL:
     *   - 'avatar'      → 'avatar_image'  (imagen de perfil de usuario)
     *   - 'gallery'     → 'gallery_image' (imagen en galería)
     *   - cualquier otro → 'gallery_image' (fallback seguro)
     * 
     * Este mapeo permite:
     *   - Configuraciones específicas por tipo de archivo
     *   - Validaciones diferentes (dimensiones, peso)
     *   - Procesamientos distintos (watermark en galerías, no en avatares)
     * 
     * @param Media $media
     * @return \App\Domain\Uploads\UploadProfile Perfil de dominio configurado
     * 
     * @throws InvalidArgumentException Si el perfil no existe en el registro
     */
    private function profileFor(Media $media): \App\Domain\Uploads\UploadProfile
    {
        $collection = $media->collection_name;
        
        // Mapeo simple de colección a ID de perfil
        // Podría extraerse a configuración si se requieren más perfiles
        $profileId = $collection === 'avatar' ? 'avatar_image' : 'gallery_image';

        return $this->profiles->get(new UploadProfileId($profileId));
    }

    /**
     * Resuelve el tenant_id aplicable al media con estrategia en cascada.
     * 
     * 🎯 ORDEN DE RESOLUCIÓN (PRIORIDAD):
     *   1. ⭐ Custom property 'tenant_id' del media
     *      - Almacenada al momento de la subida
     *      - Garantiza consistencia incluso si el owner cambia de tenant
     *   
     *   2. ⭐ Tenant del modelo owner (User::$current_tenant_id)
     *      - Relación directa usuario-tenant
     *      - Útil cuando el media no tiene tenant_id persistido
     *   
     *   3. ⭐ Tenant del contexto actual (TenantContextInterface)
     *      - Tenant activo en la petición/job
     *      - Fallback para operaciones administrativas
     *   
     *   4. ⚠️ Tenant requerido por contexto (requireTenantId)
     *      - Último recurso, lanza excepción si no hay tenant
     *      - Garantiza que NUNCA se guarde un archivo sin tenant
     * 
     * 🛡️ SEGURIDAD:
     *   Siempre sanitiza el tenant_id antes de retornarlo.
     *   Previene inyección de path traversal y caracteres especiales.
     * 
     * @param Media $media
     * @return int|string Tenant ID sanitizado
     * 
     * @throws InvalidArgumentException Si no se puede resolver un tenant válido
     */
    private function resolveTenantId(Media $media): int|string
    {
        // --- NIVEL 1: CUSTOM PROPERTY DEL MEDIA ---
        // Fuente más confiable: persistida explícitamente al crear el media
        $propTenant = $media->getCustomProperty('tenant_id');
        if ($propTenant !== null && $propTenant !== '') {
            return $this->sanitizeTenantId($propTenant);
        }

        // --- NIVEL 2: TENANT DEL MODELO OWNER ---
        // El usuario tiene un tenant actual asignado
        $owner = $media->model;
        if ($owner instanceof User && $owner->current_tenant_id !== null) {
            return $this->sanitizeTenantId($owner->current_tenant_id);
        }

        // --- NIVEL 3: TENANT DEL CONTEXTO ACTUAL ---
        // Tenant activo en este momento (petición HTTP, job queue, comando)
        $tenantId = $this->tenantContext->tenantId();
        if ($tenantId !== null) {
            return $this->sanitizeTenantId($tenantId);
        }

        // --- NIVEL 4: FALLBACK CON EXCEPCIÓN ---
        // Si llegamos aquí, es un error grave: no hay tenant en ningún nivel
        // Lanzamos excepción porque es mejor fallar ruidosamente que almacenar
        // archivos sin tenant y perder el aislamiento.
        return $this->sanitizeTenantId($this->tenantContext->requireTenantId());
    }

    /**
     * Sanitiza y valida el tenant_id para uso en rutas de archivos.
     * 
     * 🔒 REGLAS DE VALIDACIÓN:
     *   Para enteros:
     *     - Mayor a 0 (no acepta 0 ni negativos)
     *   
     *   Para strings:
     *     - No vacío ni solo espacios
     *     - Solo caracteres: A-Z, a-z, 0-9, guion (-), underscore (_)
     *     - Previene: ../, ./, \, caracteres especiales, espacios
     * 
     * 🚫 PATRONES BLOQUEADOS:
     *   - ".." (path traversal)
     *   - "/", "\" (separadores de directorio)
     *   - " " (espacios)
     *   - Caracteres no ASCII
     *   - Símbolos especiales
     * 
     * @param int|string $tenantId
     * @return int|string Tenant ID sanitizado (sin modificar, solo validado)
     * 
     * @throws InvalidArgumentException Si el tenant_id no cumple las reglas
     */
    private function sanitizeTenantId(int|string $tenantId): int|string
    {
        // --- VALIDACIÓN PARA TENANT NUMÉRICO ---
        if (is_int($tenantId)) {
            if ($tenantId <= 0) {
                throw new InvalidArgumentException(
                    'Invalid tenant id for media path: must be positive integer.'
                );
            }
            return $tenantId;
        }

        // --- VALIDACIÓN PARA TENANT STRING ---
        $trimmed = trim($tenantId);
        
        // No puede estar vacío
        if ($trimmed === '') {
            throw new InvalidArgumentException(
                'Invalid tenant id for media path: cannot be empty.'
            );
        }

        // Solo caracteres permitidos: alfanuméricos, guion, underscore
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $trimmed)) {
            throw new InvalidArgumentException(
                'Invalid tenant id for media path: contains invalid characters. ' .
                'Allowed: letters, numbers, hyphen, underscore.'
            );
        }

        return $trimmed;
    }
}