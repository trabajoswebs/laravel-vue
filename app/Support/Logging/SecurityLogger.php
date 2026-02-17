<?php

declare(strict_types=1);

namespace App\Support\Logging;

use App\Modules\Uploads\Pipeline\Security\Logging\MediaSecurityLogger;
use Psr\Log\LoggerInterface;

/**
 * WRAPPER ESTÁTICO DELGADO PARA LOGGING SEGURO DE OPERACIONES CON MEDIA
 * ===================================================================
 * 
 * 🎯 PROPÓSITO PRINCIPAL:
 *   Proporcionar una fachada estática simplificada para el logging seguro
 *   de operaciones con archivos multimedia. Actúa como punto de entrada único
 *   y consistente para todo el logging de seguridad del sistema.
 * 
 * 🏗️ PATRÓN DE DISEÑO:
 *   - FACADE: Oculta la complejidad de MediaSecurityLogger
 *   - SINGLETON: Mantiene una única instancia del logger subyacente
 *   - STATIC FACADE: Proporciona una API estática similar al logger de Laravel
 * 
 * 🔐 CARACTERÍSTICAS DE SEGURIDAD:
 *   ✅ SANITIZACIÓN AUTOMÁTICA - Todos los contextos son sanitizados
 *   ✅ SIN DATOS SENSIBLES EN LOGS - IDs, tokens, paths son ofuscados
 *   ✅ CONSISTENCIA - Misma sanitización en toda la aplicación
 * 
 * 📋 EJEMPLO DE USO:
 *   ```php
 *   SecurityLogger::info('avatar.uploaded', [
 *       'user_id' => $user->id,     // Será sanitizado
 *       'tenant_id' => $tenant->id,  // Será sanitizado
 *       'size' => 12345,            // Passthrough
 *   ]);
 *   ```
 */
final class SecurityLogger
{
    /**
     * Instancia singleton del logger de seguridad.
     * 
     * Se utiliza el patrón Singleton con inicialización lazy para:
     *   - Evitar crear la instancia si no se usa
     *   - Compartir la misma instancia en toda la aplicación
     *   - Resolver desde el contenedor solo cuando sea necesario
     * 
     * @var MediaSecurityLogger|null
     */
    private static ?MediaSecurityLogger $logger = null;

    /**
     * Registra un evento de nivel DEBUG.
     * 
     * DEBUG: Información detallada para depuración.
     * Estos logs generalmente NO se registran en producción.
     * 
     * @param string $event   Nombre del evento (ej: 'avatar.upload.started')
     * @param array  $context Contexto adicional (será sanitizado automáticamente)
     */
    public static function debug(string $event, array $context = []): void
    {
        self::logger()->debug($event, $context);
    }

    /**
     * Registra un evento de nivel INFO.
     * 
     * INFO: Eventos normales de la aplicación.
     * Ejemplos: archivo subido, procesamiento iniciado, conversión completada.
     * 
     * @param string $event   Nombre del evento
     * @param array  $context Contexto adicional sanitizado
     */
    public static function info(string $event, array $context = []): void
    {
        self::logger()->info($event, $context);
    }

    /**
     * Registra un evento de nivel WARNING.
     * 
     * WARNING: Eventos inesperados pero no críticos.
     * Ejemplos: archivo obsoleto, retry automático, lock no adquirido.
     * 
     * @param string $event   Nombre del evento
     * @param array  $context Contexto adicional sanitizado
     */
    public static function warning(string $event, array $context = []): void
    {
        self::logger()->warning($event, $context);
    }

    /**
     * Registra un evento de nivel ERROR.
     * 
     * ERROR: Errores recuperables que requieren atención.
     * Ejemplos: fallo en validación, archivo corrupto, timeout.
     * 
     * @param string $event   Nombre del evento
     * @param array  $context Contexto adicional sanitizado
     */
    public static function error(string $event, array $context = []): void
    {
        self::logger()->error($event, $context);
    }

    /**
     * Registra un evento de nivel CRITICAL.
     * 
     * CRITICAL: Errores graves que requieren intervención inmediata.
     * Ejemplos: virus detectado, fallo en componente crítico, pérdida de datos.
     * 
     * @param string $event   Nombre del evento
     * @param array  $context Contexto adicional sanitizado
     */
    public static function critical(string $event, array $context = []): void
    {
        self::logger()->critical($event, $context);
    }

    /**
     * Registra un evento con nivel dinámico.
     * 
     * Útil cuando el nivel se determina en tiempo de ejecución.
     * Incluye fallback seguro a INFO si el nivel no es válido.
     * 
     * @param string $level   Nivel de log ('debug','info','warning','error','critical')
     * @param string $event   Nombre del evento
     * @param array  $context Contexto adicional sanitizado
     * 
     * @example
     * ```php
     * $level = $critical ? 'critical' : 'warning';
     * SecurityLogger::log($level, 'job.processed', $context);
     * ```
     */
    public static function log(string $level, string $event, array $context = []): void
    {
        $level = strtolower(trim($level));

        match ($level) {
            'debug'     => self::logger()->debug($event, $context),
            'info'      => self::logger()->info($event, $context),
            'warning'   => self::logger()->warning($event, $context),
            'error'     => self::logger()->error($event, $context),
            'critical'  => self::logger()->critical($event, $context),
            // ⚠️ FALLBACK SEGURO: Nivel inválido → INFO + contexto adicional
            default     => self::logger()->info($event, array_merge($context, [
                'invalid_level' => $level, // Registramos el nivel original inválido
            ])),
        };
    }

    /**
     * Obtiene un canal de log específico.
     * 
     * Permite acceder directamente a canales de Laravel Log (stack, single, slack, etc.)
     * Útil para casos donde se necesita un canal específico no relacionado con seguridad.
     * 
     * @param string $channel Nombre del canal (configurado en config/logging.php)
     * @return LoggerInterface Instancia del canal solicitado
     * 
     * @example
     * ```php
     * SecurityLogger::channel('slack')->warning('Alerta en Slack', $context);
     * ```
     */
    public static function channel(string $channel): LoggerInterface
    {
        return \Illuminate\Support\Facades\Log::channel($channel);
    }

    /**
     * Obtiene la instancia singleton del logger de seguridad.
     * 
     * Implementa inicialización lazy:
     *   - Primera llamada: Resuelve del contenedor y almacena
     *   - Llamadas subsecuentes: Retorna instancia almacenada
     * 
     * 🔧 BENEFICIOS:
     *   - Performance: Una sola resolución del contenedor
     *   - Consistencia: Misma instancia en toda la aplicación
     *   - Testing: Fácilmente reemplazable con mock
     * 
     * @return MediaSecurityLogger Instancia del logger con sanitización automática
     */
    private static function logger(): MediaSecurityLogger
    {
        // ⚡ Lazy initialization: Resuelve solo cuando es necesario
        // El operador ??= (null coalescing assignment) mantiene la instancia
        return self::$logger ??= app(MediaSecurityLogger::class);
    }
}
