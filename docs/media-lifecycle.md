# Media Lifecycle Coordination

## Flujo Replace → Conversion → Cleanup

1. `MediaLifecycleCoordinator::replace()` delega en `MediaReplacementService` para subir el nuevo archivo y devuelve un `ReplacementResult` con:
   - `media`: modelo fresco de Spatie.
  - `snapshot`: artefactos (por disco) del media anterior (`ReplacementSnapshot`).
   - `expectations`: conversions esperadas (`ConversionExpectations`).
2. Tras el commit de BD se arma un `CleanupPayload` y se invoca `MediaCleanupScheduler::scheduleCleanup()` usando el ID del media nuevo.
3. Cuando Spatie publica `ConversionHasBeenCompletedEvent` o `ConversionHasFailedEvent`, `RunPendingMediaCleanup` reenvía el evento al scheduler. Si tras varios reintentos el job `PerformConversionsJob` falla, o el media desaparece, también se fuerza `flushExpired()` para despachar el cleanup.

## Contratos de Eventos

| Evento | Propiedades requeridas | Consumidores |
| ------ | ---------------------- | ------------ |
| `Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent` | `public Media $media`, `public ?string $conversionName` | `RunPendingMediaCleanup`, `QueueAvatarPostProcessing` |
| `Spatie\MediaLibrary\Conversions\Events\ConversionHasFailedEvent` | `public Media $media`, `public ?string $conversionName` | `RunPendingMediaCleanup` |
| `App\Events\User\AvatarUpdated` | `User $user`, `Media $newMedia`, `?Media $oldMedia`, `?string $version`, `string $collection`, `string $url` | Audit listeners, métricas (opcional) |

> ⚠️ Los listeners confían en estas propiedades públicas. Cualquier cambio en el paquete de Spatie debe revisarse contra este contrato.

## Métricas e Instrumentación

- `media_cleanup.conversions_progress`: log informativo con conversions esperadas/generadas y ratio pendiente.
- `media.conversions.retrying` / `media.conversions.failed_permanently`: avisan reintentos/fallos definitivos del job de conversions.
- `media.cleanup.observer_*`: alertan fallos al intentar limpiar payloads tras borrar un media.

Recomendación: ingestar estos logs en el pipeline de observabilidad (ELK/Datadog) y generar alertas en:
- Ratio de conversions pendientes > 0.75 durante >15 min.
- Jobs `PerformConversionsJob` con `attempts >= tries`.
- Payloads que requieren `flushExpired` repetidamente.

## Auditoría de Storage (acciones propuestas)

1. **Versionado en S3**: habilitar `VersioningConfiguration` para buckets que almacenan originales. Permite recuperar archivos si la limpieza falla y sirve como safety net.
2. **Lifecycle Policies**: definir expiración automática de prefijos `conversions/` y `responsive-images/` mayores a 30 días como fallback ante cualquier bug.
3. **Cost Tracking**: registrar métricas de tamaño/objetos por colección para detectar leaks pronto (CloudWatch Metrics + tags por colección/entorno).
4. **Backfill**: antes de activar políticas, correr un script que use `CleanupPayload` sobre medias sin conversions pendientes para evitar borrados masivos.
