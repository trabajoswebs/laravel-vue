# Uploads Hub

`app/Modules/Uploads` centraliza el ciclo completo de archivos: validación, quarantine/scanning, persistencia, serving y lifecycle de limpieza.

## Módulos

- `Http/`: controladores, FormRequests, middleware, reglas y helpers de respuesta HTTP.
- `Core/`: orquestador, registry de perfiles, pathing tenant-aware, repositorios, modelos y servicios base.
- `Pipeline/`: ejecución técnica de upload (seguridad, imagen, scanning, quarantine, optimizer, jobs/listeners/observers, support).
- `Profiles/`: perfiles de upload por caso de uso (avatar, galería, documentos, secretos, imports).
- `Providers/`: bindings e integración con Laravel/Spatie.

## Flujo resumido

1. Request valida entrada (`StoreUploadRequest` / `UploadImageRequest` / `ReplaceUploadRequest`).
2. `Application\Uploads\Actions\UploadFile|ReplaceFile` delega al orquestador.
3. `DefaultUploadOrchestrator` resuelve perfil y estrategia (imagen/documento/secreto).
4. Pipeline aplica seguridad (magic bytes, normalización, escaneo, quarantine).
5. Se persiste en disco/rutas tenant-aware y en metadata cuando aplica.
6. Serving controlado por controlador, policy y configuración de `ServingMode`.
7. Jobs de post-proceso y cleanup (`CleanupMediaArtifactsJob`) mantienen conversiones/artefactos.

## Checklist de configuración

- `config/image-pipeline.php`: límites, normalización, scanner, rate limit.
- `config/media-serving.php`: allowlist de rutas servibles.
- `config/filesystems.php`: discos usados por perfiles.
- Workers de cola activos para jobs de post-proceso y cleanup.

## Archivos clave

- Orquestación: `Core/Orchestrators/DefaultUploadOrchestrator.php`
- Pipeline base: `Pipeline/DefaultUploadPipeline.php`
- Servicio de upload: `Pipeline/DefaultUploadService.php`
- Serving media: `Http/Controllers/Media/ShowMediaController.php`
- Serving avatar firmado: `Http/Controllers/Media/ShowAvatar.php`
- Limpieza artefactos: `Pipeline/Jobs/CleanupMediaArtifactsJob.php`
- Builder de limpieza: `Pipeline/Support/MediaCleanupArtifactsBuilder.php`
