# Arquitectura de `app/`

Estructura por capas con regla de dependencias:

- `Domain` <- `Application` <- `Infrastructure`
- `Support` contiene utilidades compartidas sin lógica de caso de uso.

## Capas

### `Domain/`

Modelo de dominio y reglas puras:

- `Uploads/*`: value objects y enums de perfiles/modos de upload.
- `Security/Rules/*`: reglas de dominio para seguridad (headers, firmas).

### `Application/`

Casos de uso y contratos:

- `Uploads/*`: acciones (`UploadFile`, `ReplaceFile`), contratos y DTOs.
- `User/*`: flujo de avatar (acciones, eventos, DTOs, jobs de aplicación).
- `Shared/Contracts/*`: contratos transversales (clock, logger, event bus, tx, jobs).

### `Infrastructure/`

Adaptadores técnicos y wiring Laravel:

- `Http/*`: controladores, middleware y requests generales.
- `Uploads/*`: hub de media/uploads (http, core, pipeline, profiles, providers).
- `Auth/*`, `Tenancy/*`, `User/*`, `Security/*`, `Shared/*`: políticas, tenant, repositorios, seguridad y adaptadores.
- `Console/*`, `Providers/*`, `Localization/*`, `Models/*`: bootstrap y runtime.

### `Support/`

Utilidades de soporte no acopladas al dominio principal:

- `Media/TenantAwareUrlGenerator.php`
- `Logging/SecurityLogger.php`

## Referencias

- Árbol completo de `app/`: `app_tree.txt`
- Detalle del hub de uploads: `app/Infrastructure/Uploads/README.md`
