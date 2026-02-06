# Uploads Hub

Hub único para perfiles de dominio, pipeline de seguridad/AV, cuarentena, serving y glue con Media Library. Rutas HTTP se mantienen tal cual; esto aclara la ruta feliz por kind.

## Mapa de carpetas (tenant-first)
- `Http/`: controladores (`DownloadUploadController`, `UploadController`), FormRequests (`StoreUploadRequest`, `ReplaceUploadRequest`, `UploadImageRequest`), adaptador `HttpUploadedMedia`, concerns (`UsesImageValidation`, `UsesDocumentValidation`), regla `SecureImageValidation`, middleware (`RateLimitUploads`, `TrackMediaAccess`).
- `Core/`: orquestador (`Core/Orchestrators/DefaultUploadOrchestrator`), registry (`Core/Registry/UploadProfileRegistry`), paths tenant-first (`Core/Paths/*`), repositorio tabular (`Core/Repositories/EloquentUploadRepository`), modelos (`Upload`, `MediaCleanupState`, `TracksMediaVersions`), adaptador `SpatieMediaResource`.
- `Pipeline/`: pipelines y seguridad (`DefaultUploadPipeline`, `ImageUploadPipelineAdapter`, `Security/*`, `Scanning/*`, `Quarantine/*`, `Optimizer/*`, `Jobs|Listeners|Observers`, `Services/*`, `Support/*`, `Contracts`, `Exceptions`, `Health/UploadPipelineHealthCheck`).
- `Profiles/`: perfiles de dominio (PDF/XLSX/CSV/secretos/imágenes) y perfiles Spatie (avatar, gallery).
- `Providers/`: bindings del hub (`UploadsServiceProvider`, `ImagePipelineServiceProvider`, `MediaLibraryBindingsServiceProvider`).

## Ruta feliz por kind (resumen)
- **Image**: Request (`UploadImageRequest` o `StoreUploadRequest` con `UploadKind::IMAGE`) → `HttpUploadedMedia` → `Application\Uploads\Actions\UploadFile` → `DefaultUploadOrchestrator` → `MediaReplacementService` → `DefaultUploadService` (pipeline imagen: magic bytes, normalize, AV/YARA) → MediaLibrary → post-proceso (jobs de conversions + optimización) → Serving `controller_signed`.
- **Document**: Request (`StoreUploadRequest` con perfil document/spreadsheet/import) → `HttpUploadedMedia` → `UploadFile` → `DefaultUploadOrchestrator` → `QuarantineManager` duplica → `ScanCoordinator` (AV/YARA) → guarda a disco con `TenantPathGenerator` → `UploadRepositoryInterface` persiste metadata → Serving `controller_signed`.
- **Secret**: Request (`StoreUploadRequest` con perfil secret) → mismas validaciones de documento → cuarentena + AV → guarda en disco privado → `ServingMode::FORBIDDEN` (sin endpoint de descarga, solo uso interno).

## Etapas obligatorias (todas)
1) Request validation: FormRequest + `UsesImageValidation`/`UsesDocumentValidation` (+ `SecureImageValidation` para imágenes).
2) Magic bytes/MIME: `MagicBytesValidator` (pipeline) o firmas mínimas en `DefaultUploadOrchestrator::validateDocument`.
3) Scan/quarantine: `QuarantineManager` + `ScanCoordinatorInterface` (AV/YARA) según `ScanMode` (`required` para todos los perfiles actuales).
4) Persist: rutas tenant-first (`TenantPathGenerator`, `MediaLibrary/TenantAware*`), escribe a disco y, para documentos/secretos, persiste metadata vía `UploadRepositoryInterface`.
5) Serving: `ServingMode` controla exposición (`controller_signed` → `DownloadUploadController`; `forbidden` → sin endpoint).
6) Post-process (imágenes): conversions Spatie + `PostProcessAvatarMedia`/optimización; limpieza vía `CleanupMediaArtifactsJob`.

## Checklist de validaciones por etapa
- Request: tamaño (`File::max`), mimetypes permitidos, dimensiones (imágenes), `SecureImageValidation`.
- Magic bytes/firma: `MagicBytesValidator` (pipeline) y firma mínima en `validateDocument` (PDF/XLSX/CSV/secret).
- Antivirus/YARA: `ScanCoordinator` y `config('uploads.virus_scanning.enabled')`.
- Cuarentena: duplicado en disco segregado, token trackeado.
- Pathing: `TenantPathGenerator` / `TenantAwarePathGenerator`, sin traversal.
- Policies: `UploadPolicy@create|download|replace|delete` + tenant/owner checks.
- Rate limiting / audit: `RateLimitUploads`, `TrackMediaAccess` (si activado), logs de seguridad.

## Qué se ejecuta por UploadProfile
| profile_id | kind | scanMode | processingMode | servingMode | disk / path_category |
| --- | --- | --- | --- | --- | --- |
| avatar_image | image | required | image_pipeline | controller_signed | disk `image-pipeline.avatar_disk` (fallback `filesystems.default`) / avatars |
| gallery_image | image | required | image_pipeline | controller_signed | disk `filesystems.default` / images |
| document_pdf | document | required | none | controller_signed | disk `filesystems.default` / documents |
| spreadsheet_xlsx | spreadsheet | required | none | controller_signed | disk `filesystems.default` / spreadsheets |
| import_csv | import | required | none | forbidden | disk `filesystems.default` / imports |
| certificate_secret | secret | required | none | forbidden | disk `filesystems.default` (private) / secrets |

## Cómo crear un nuevo UploadProfile
1) Clase en `Profiles/` extendiendo `Domain\Uploads\UploadProfile` con `UploadKind`, `allowedMimes`, `maxBytes`, `scanMode`, `processingMode`, `servingMode`, `disk`, `pathCategory`, `requiresOwner`.
2) Registrar en `UploadsServiceProvider` dentro del array de `UploadProfileRegistry`.
3) Exponer endpoint: FormRequest (`UploadImageRequest` o `StoreUploadRequest` según kind), controlador que llame `Application\Uploads\Actions\UploadFile|ReplaceFile`, resolviendo el perfil desde el registry y envolviendo el archivo con `HttpUploadedMedia`.
4) Imágenes: mantener/crear perfil Spatie en `Profiles/*` y conversions necesarias. Documentos/secretos: añadir validadores livianos si se necesita inspección extra.

## Descarga y serving
- `Http/Controllers/DownloadUploadController` autoriza con `UploadPolicy@download` (tenant + bloqueo de `certificate_secret`); si el disco es S3 usa `temporaryUrl`, de lo contrario `Storage::download`.
- `Http/Controllers/Media/ShowMediaController` (ruta `/media/{path}`) ahora aplica una allowlist configurable en `config/media-serving.php` para evitar servir archivos arbitrarios. Ajusta `allowed_paths` si agregas nuevas rutas de media tenant-first.
- `ServingMode`:
  - `controller_signed`: servido vía controlador con policy/tenant y, si aplica, URL temporal del driver (avatar/doc/pdf/xlsx/csv firmado o autorizado).
  - `forbidden`: no se expone endpoint de descarga (`import_csv`, `certificate_secret`), solo uso interno/procesamiento.
