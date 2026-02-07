# Arquitectura de `app/`

Capas (regla de dependencias: Domain ← Application ← Infrastructure):
- **Domain** → Entidades, Value Objects, contratos puros, reglas inmutables. No depende de Application ni Infrastructure.
- **Application** → Casos de uso, coordinadores, Actions/Jobs/Listeners/Policies, DTOs y Requests. Solo depende de Domain.
- **Infrastructure** → Adaptadores técnicos (HTTP, Console, Providers, Pipelines, Uploads, Security, Localization), integración con Laravel y servicios externos. Puede depender de Application y Domain.

Mapa rápido:
- `Domain/`
  - `Media/` → Contrato `Contracts/MediaResource` y DTOs de snapshots/conversions (soporte actual). **Legacy**: se mantiene hasta simplificar a Uploads-only.
  - `Uploads/` → VOs (`UploadProfile`, `UploadProfileId`, `UploadKind`, `ScanMode`, `ProcessingMode`, `ServingMode`).
  - `Security/Rules/` → Reglas para headers de avatar y firmas de rate limiting.
- `Application/`
  - `Uploads/` → Actions (`UploadFile`, `ReplaceFile`), orquestador contract y DTOs (`UploadResult`, `ReplacementResult`), repositorio contract.
  - `Media/` → `MediaReplacementService` y puertos (`MediaProfile`, `MediaOwner`, `UploadedMedia`, `MediaUploader`, `MediaArtifactCollector`, `MediaCleanupScheduler`) usados por el pipeline actual. **Legacy eliminado**: toda esta funcionalidad vive ahora en `Infrastructure/Uploads/Core/{Contracts,DTO,Services}`.
  - `User/` → Actions de avatar (`UpdateAvatar`, `DeleteAvatar`), eventos (`AvatarUpdated/Deleted`), puertos/repos (`Contracts/*`), DTOs (`AvatarUpdateResult`, `AvatarDeletionResult`), mensaje `CleanupMediaArtifacts`, enum `ConversionReadyState`.
  - `Shared/Contracts/` → Puertos de reloj/logger/event bus/colas/transacciones.
- `Infrastructure/`
  - `Http/` → Controladores Auth/Settings/Language; base `Controller`, middlewares de seguridad/auditoría y FormRequests genéricas. Uploads expone controllers/FormRequests/middleware/rules en `Infrastructure/Uploads/Http` (incl. `SecureImageValidation` y `RateLimitUploads`).
  - `Uploads/` → Hub único: Http (controllers/FormRequests/middleware + `HttpUploadedMedia`), Core (Orchestrator, registry de perfiles, paths tenant-first, repositorios/adaptadores/modelos, contratos/DTOs y servicios de media), Pipeline (ImagePipeline + UploadPipeline, cuarentena, scanning AV/Yara, seguridad, optimizador, jobs/listeners/observers, health check), scheduler de cleanup y providers (MediaLibrary, ImagePipeline, Uploads). Todos los endpoints de subida/descarga/serving se apoyan en este hub.
  - `Console/` → Kernel y comandos `quarantine:*`, `CleanAuditLogs`.
  - `Providers/` → App/Auth/Event/HtmlPurifier/ImagePipeline y Tenancy.
  - `Localization/` → `TranslationService`.
  - `Models/` → Eloquent (`User`).
  - `Auth/Policies/` → Políticas (`UserPolicy`, `Concerns/HandlesMediaOwnership`).
  - `Shared/Adapters/` → Adaptadores Laravel para puertos transversales.
  - `User/Adapters`, `User/Events/` → Adaptadores/bridge para usuario/avatar.
  - `Security/` → `AvatarHeaderInspector`, `SecurityHelper`, `RateLimitSignatureFactory`, excepciones de antivirus.
  - `Sanitization/` → Helpers/VOs (`DisplayName`).
- `Shared root/` → Alias `Console/Kernel.php` (compatibilidad).

## Flujo oficial para subir/servir/descargar
- Subir (creación): Controller → FormRequest → `Application/Uploads/Actions/UploadFile` → `Infrastructure/Uploads/Core/Orchestrators/DefaultUploadOrchestrator`.
  - Imágenes (`UploadKind::IMAGE`): pasa por `MediaReplacementService` → `MediaUploader` (DefaultUploadService + DefaultUploadPipeline) → Spatie Media Library con paths tenant-first (`Uploads/Core/Paths/MediaLibrary/*`).
  - Documentos/secrets: `DefaultUploadOrchestrator` valida, duplica a cuarentena (`QuarantineManager`), escanea (`ScanCoordinatorInterface`), genera path con `TenantPathGenerator`, guarda en disco y persiste metadata vía `UploadRepositoryInterface` (Eloquent).
- Reemplazo: Controller → FormRequest → `Application/Uploads/Actions/ReplaceFile` → mismo orquestador, devuelve `ReplacementResult` (nuevo `UploadResult` + previo opcional).
- Serving:
  - Media Library: URLs firmadas/inline por Spatie (`ShowAvatar` u otras vistas); paths generados por `Uploads/Core/Paths/MediaLibrary/TenantAwarePathGenerator` y `TenantAwareFileNamer`.
  - Uploads tabulares: `Infrastructure/Uploads/Http/Controllers/DownloadUploadController` valida tenant/policy, genera descarga directa o URL temporal.

## Cómo crear un nuevo `UploadProfile`
1. Crear clase en `Infrastructure/Uploads/Profiles/*` extendiendo `Domain/Uploads/UploadProfile` con `UploadKind`, `allowedMimes`, `maxBytes`, `scanMode`, `processingMode`, `servingMode`, `disk`, `pathCategory`, `requiresOwner`.
2. Registrar el perfil en `Infrastructure/Uploads/Providers/UploadsServiceProvider` dentro del array de `UploadProfileRegistry`.
3. Exponer endpoint: FormRequest específico + Controller que invoque `UploadFile` o `ReplaceFile`, pasando el `UploadProfile` resuelto desde el registry y el `HttpUploadedMedia`.
4. Si `UploadKind::IMAGE`, definir colección/conversions en `Infrastructure/Uploads/Profiles/*` (y, si requiere, ajustes en `Pipeline/Image/*`); si no es imagen, solo ajustar políticas de descarga/visibilidad.

## Nota rápida sobre tests y `config:cache`
- No caches configuración antes de correr `artisan test`. `config:cache` fija la config del `.env` actual y los tests necesitan la de `phpunit.xml/.env.testing`.  
- Antes de test: `./vendor/bin/sail artisan config:clear` (o `php artisan config:clear` sin Sail).  
- Si cacheas para producción, limpia de nuevo antes de ejecutar la suite.

## Componentes deprecados/eliminados
- `Infrastructure/Media/` → DEPRECATED. Vacío salvo `README.md`; toda la lógica vive en `Infrastructure/Uploads`.
- Eliminados: `Application/Media/*` y `Domain/Media/*` (puertos/DTOs) fueron absorbidos por `Infrastructure/Uploads/Core/{Contracts,DTO,Services}`.
- Eliminados por duplicados: `app/Infrastructure/Media/DTO/ReplacementResult.php`, `ReplacementSnapshot.php`, `ReplacementSnapshotItem.php` (usaban snapshots redundantes con los DTO de Domain/Application). Usar `Infrastructure/Uploads/Core/DTO/MediaReplacementResult` y snapshots de `Core/DTO`.
