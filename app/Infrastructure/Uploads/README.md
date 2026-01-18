# Uploads Hub

Este directorio es el hub único para perfiles de dominio, pipeline de seguridad/AV, cuarentena, rutas de serving y glue con Media Library.

## Mapa de carpetas (tenant-first)
- `Http/`: controladores (`DownloadUploadController`, `Settings/ProfileAvatarController`, `Media/ShowAvatar`), FormRequests (`UploadImageRequest`, `Settings/UpdateAvatarRequest`, `Settings/DeleteAvatarRequest`), adaptador `HttpUploadedMedia`, concerns (`UsesImageValidation`, `UsesDocumentValidation`), regla `SecureImageValidation`, middleware (`RateLimitUploads`, `TrackMediaAccess`).
- `Core/`: orquestador (`Core/Orchestrators/DefaultUploadOrchestrator`), registro (`Core/Registry/UploadProfileRegistry`), paths tenant-first (`Core/Paths/TenantPathGenerator`, `Core/Paths/MediaLibrary/*`), repositorio tabular (`Core/Repositories/EloquentUploadRepository`), modelos (`Upload`, `MediaCleanupState`, `TracksMediaVersions`), adaptador `SpatieMediaResource`.
- `Pipeline/`: pipelines y seguridad (`DefaultUploadPipeline`, `ImageUploadPipelineAdapter`, `Security/*`, `Scanning/*`, `Quarantine/*`, `Optimizer/*`, `Jobs|Listeners|Observers`, `Services/*`, `Support/*`, `Contracts`, `Exceptions`, `Health/UploadPipelineHealthCheck`), más el pipeline de imagen (`Pipeline/Image/*`).
- `Profiles/`: perfiles de dominio (PDF/XLSX/CSV/secretos/imágenes) y perfiles Spatie (`AvatarProfile`, `GalleryProfile`).
- `Providers/`: bindings del hub (`UploadsServiceProvider`, `ImagePipelineServiceProvider`, `MediaLibraryBindingsServiceProvider`).

## Flujo oficial de subida
1. Controller recibe una FormRequest (`UploadImageRequest`, `UpdateAvatarRequest`, etc.) que aplica `UsesImageValidation` o `UsesDocumentValidation` y construye `HttpUploadedMedia`.
2. La FormRequest/Controller invoca la Action (`UploadFile`, `ReplaceFile`, `UpdateAvatar`) pasando el `UploadProfile` resuelto desde `UploadProfileRegistry`.
3. La Action delega en `DefaultUploadOrchestrator`, que enruta por `UploadKind` (imagen → MediaReplacementService + ImagePipeline; documentos/secretos → cuarentena + antivirus + persistencia).
4. El orquestador genera el path tenant-first con `TenantPathGenerator` y delega en `DefaultUploadService` (imágenes) o `EloquentUploadRepository` (documentos/secretos) para escribir disco + tabla.
5. Artefactos y auditoría viajan por `MediaArtifactCollector`, `UploadSecurityLogger`, jobs/listeners y `MediaCleanupScheduler`.

## Cómo crear un nuevo UploadProfile
1. Crear clase en `Profiles/` extendiendo `Domain\Uploads\UploadProfile` con `UploadKind`, `allowedMimes`, `maxBytes`, `scanMode`, `processingMode`, `servingMode`, `disk`, `pathCategory`, `requiresOwner`.
2. Registrar el perfil en `UploadsServiceProvider` dentro del array de `UploadProfileRegistry`.
3. Exponer endpoint: FormRequest específica usando `UsesImageValidation` o `UsesDocumentValidation`, controlador que llame `Application\Uploads\Actions\UploadFile` o `ReplaceFile`, resolviendo el perfil desde el registry y envolviendo el archivo con `Uploads\Http\Requests\HttpUploadedMedia`.
4. Para imágenes, mantener/crear el perfil Spatie en `Profiles/*` y conversions necesarias. Para documentos/secretos, añadir validadores livianos (sin parsing pesado) si se necesita inspección extra.

## Descarga y serving
- `Http/Controllers/DownloadUploadController` autoriza con `UploadPolicy@download` (tenant + bloqueo de `certificate_secret`); si el disco es S3 usa `temporaryUrl`, de lo contrario `Storage::download`.
- `Http/Controllers/Media/ShowAvatar` sirve conversions firmadas (`media.avatar.show`) con `signed` + middleware `media.access` (logger `TrackMediaAccess`).
- `ServingMode`:
  - `controller_signed`: servido vía controlador con policy/tenant y, si aplica, URL temporal del driver (avatar/doc/pdf/xlsx/csv firmado o autorizado).
  - `forbidden`: no se expone endpoint de descarga (`import_csv`, `certificate_secret`), solo uso interno/procesamiento.

## Perfiles registrados
| profile_id | kind | scan | processing | serving | disk / path_category |
| --- | --- | --- | --- | --- | --- |
| avatar_image | image | required | image_pipeline | controller_signed | disk `image-pipeline.avatar_disk` (fallback `filesystems.default`) / avatars |
| gallery_image | image | required | image_pipeline | controller_signed | disk `filesystems.default` / images |
| document_pdf | document | required | none | controller_signed | disk `filesystems.default` / documents |
| spreadsheet_xlsx | spreadsheet | required | none | controller_signed | disk `filesystems.default` / spreadsheets |
| import_csv | import | required | none | forbidden | disk `filesystems.default` / imports |
| certificate_secret | secret | required | none | forbidden | disk `filesystems.default` (private) / secrets |
