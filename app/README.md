# Arquitectura de `app/`

Capas y dependencias permitidas:
- **Domain** → Entidades/Value Objects/Contratos/Reglas puras. No depende de Application ni Infrastructure.
- **Application** → Casos de uso, coordinadores, Actions/Jobs/Listeners/Policies, DTOs y Requests. Solo depende de Domain.
- **Infrastructure** → Adaptadores técnicos (HTTP, Console, Providers, Pipelines, Upload, Security, Localization), integración con Laravel y servicios externos. Puede depender de Application y Domain.

Mapa actual (simplificado):
- `Domain/`
  - `Media/` → Contrato de recurso (`Contracts/MediaResource`) y VOs agnósticos (`DTO/*` snapshots y conversiones).
  - `Security/Rules/` → Reglas inmutables para headers de avatar y firmas de rate limiting.
- `Application/`
  - `Media/` → Coordinadores y handlers de lifecycle/cleanup (`MediaLifecycleCoordinator`, `MediaReplacementService`), puertos (`MediaProfile`, `MediaOwner`, `UploadedMedia`, uploader/scheduler/collector), VO `FileConstraints` y DTOs de cleanup/reemplazo.
  - `User/` → Actions avatar, Events (`AvatarUpdated/Deleted`), puertos/repos (`Contracts/*`), DTOs (`AvatarUpdateResult`, `AvatarDeletionResult`), mensaje `CleanupMediaArtifacts` (puro) y enum `ConversionReadyState`.
  - `Shared/Contracts/` → Puertos transversales para reloj/logger/event bus/colas/transacciones.
- `Infrastructure/`
  - `Console/` → Kernel y comandos (`quarantine:*`, `CleanAuditLogs`).
  - `Http/` → Controladores Auth/Settings/Media/Language; base `Controller`, middlewares de seguridad/rate limiting/auditoría, FormRequests y regla `SecureImageValidation`.
  - `Media/` → ImagePipeline (Imagick/Fallback, `PipelineConfig`, `PipelineLogger`), contrato `MediaProfile` + perfiles (`Profiles/*`, `ConversionProfiles/*`), adaptadores (`Adapters/SpatieMediaResource`, `Adapters/HttpUploadedMedia`), servicio de subida unificado (`DefaultUploadService` + `DefaultUploadPipeline`), jobs/listeners de conversions (`Media/Jobs`, `Media/Listeners`), módulo de uploads (`Upload/*`: cuarentena, ScanCoordinator, reporter/manager, excepciones), optimizador y adapters, seguridad (`PayloadScanner`, normalizadores, scanners ClamAV/Yara, `UploadValidationLogger`), DTOs de replace, modelo `MediaCleanupState` + `Concerns/TracksMediaVersions`, servicio `MediaCleanupScheduler` y `Observers/MediaObserver`.
  - `Localization/` → `TranslationService`.
  - `Models/` → Modelos Eloquent (`User`).
  - `Auth/Policies/` → Políticas de autorización (`UserPolicy`, `Concerns/HandlesMediaOwnership`).
  - `Shared/Adapters/` → Implementaciones Laravel de los puertos de `Application/Shared` (colas, reloj, logger, eventos, transacciones).
  - `User/Adapters/` → Implementaciones Eloquent/Spatie de los repositorios de usuario/avatar.
  - `User/Events/` → Wrappers de eventos de aplicación listos para Laravel.
  - `Providers/` → Service providers Laravel (App/Auth/Event/HtmlPurifier/ImagePipeline/MediaLibrary).
  - `Security/` → `AvatarHeaderInspector`, `SecurityHelper`, `RateLimitSignatureFactory`, excepciones de antivirus.
  - `Sanitization/` → Value Objects y helpers de sanitización (`DisplayName`).
- `Shared root/` → Alias `Console/Kernel.php` (compatibilidad).

Regla de dependencias: Domain ← Application ← Infrastructure. Mantén los cambios dentro de su capa y evita referencias cruzadas que violen esta dirección.
