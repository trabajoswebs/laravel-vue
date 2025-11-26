# Arquitectura de `app/`

Capas y dependencias permitidas:
- **Domain** → Entidades/Value Objects/Contratos/Reglas puras. No depende de Application ni Infrastructure.
- **Application** → Casos de uso, coordinadores, Actions/Jobs/Listeners/Policies, DTOs y Requests. Solo depende de Domain.
- **Infrastructure** → Adaptadores técnicos (HTTP, Console, Providers, Pipelines, Upload, Security, Localization), integración con Laravel y servicios externos. Puede depender de Application y Domain.

Mapa actual (simplificado):
- `Domain/`
  - `User/` → Modelo `User`, contratos (`Contracts/MediaOwner`), perfiles de media (`Profiles/AvatarProfile`, `GalleryProfile`), conversion profiles (`ConversionProfiles/*`).
  - `Media/` → Perfiles genéricos (`ImageProfile`), DTOs de media.
  - `Security/` → `SecurityHelper`, `RateLimitSignatureFactory`.
  - `Sanitization/` → `DisplayName`.
- `Application/`
  - `Http/Requests/` → FormRequests y traits de sanitización.
  - `Media/` → Coordinadores y servicios de lifecycle/cleanup (`MediaLifecycleCoordinator`, `MediaCleanupScheduler`, `MediaReplacementService`), DTOs de cleanup.
  - `User/` → Actions avatar, Events (`AvatarUpdated/Deleted`), Jobs (`PerformConversionsJob`, `CleanupMediaArtifactsJob`, `PostProcessAvatarMedia`), Listeners de cleanup/queue, Policies (`UserPolicy`).
- `Infrastructure/`
  - `Http/Controllers/` → Controladores Auth/Settings/Media/Language; base `Controller`. Middleware de seguridad, rate limiting, auditoría, Inertia, proxies, sanitización.
  - `Console/` → Kernel y comandos (`quarantine:*`, `CleanAuditLogs`).
  - `Localization/` → `TranslationService`.
  - `Media/` → Pipelines de imagen, upload/quarantine, optimizador, scanners, providers, concerns (`GuardsUploadedImage`), modelo `MediaCleanupState`.
  - `Providers/` → Service providers Laravel (App/Auth/Event/HtmlPurifier).
  - `Security/` → `AvatarHeaderInspector`.
  - `Http/Rules/` → `SecureImageValidation` (validación de imágenes).
  - `Media/Models/Concerns/` → `TracksMediaVersions`.
  - `Media/Observers/` → `MediaObserver`.
- `Shared root/` → Alias `Console/Kernel.php` (compatibilidad).

Regla de dependencias: Domain ← Application ← Infrastructure. Mantén los cambios dentro de su capa y evita referencias cruzadas que violen esta dirección.
