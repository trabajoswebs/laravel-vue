<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Middleware;

use App\Models\User;
use App\Models\Tenant;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware que resuelve y establece el tenant activo para la solicitud
 * Valida que el usuario tenga acceso al tenant configurado y mantiene consistencia de sesión
 */
class ResolveTenant
{
    private const SESSION_KEY_CONFIG = 'multitenancy.current_tenant_id_key'; // Clave de configuración para session key
    private const CACHE_TTL = 300;                                           // Tiempo de vida de cache (5 minutos)
    private const CACHE_PREFIX = 'user_tenant_access:';                     // Prefijo para claves de cache

    /**
     * Maneja la solicitud para resolver y establecer el tenant activo
     * 
     * @param Request $request Solicitud HTTP entrante
     * @param Closure $next Callback para continuar la cadena de middleware
     * @return Response Respuesta generada
     * @throws AuthenticationException Si el usuario no está autenticado
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            // Lanza excepción estándar de Laravel para que el Handler decida (Login redirect o 401 JSON)
            throw new AuthenticationException('Unauthenticated.');
        }

        $tenant = $this->resolveTenantForUser($user);

        if (! $tenant) {
            $this->logSecurityEvent('tenant_resolution_failed', $request, [
                'user_id' => $user->getKey(),
                'target_tenant_id' => $user->getCurrentTenantId()
            ]);
            
            return abort(403, 'No tienes acceso al tenant configurado.');
        }

        $tenant->makeCurrent(); // Establece el tenant como activo para esta solicitud

        if (! $this->ensureSessionConsistency($request, $tenant)) {
            $this->logSecurityEvent('session_tenant_mismatch', $request, [
                'user_id' => $user->getKey(),
                'expected_tenant' => $tenant->getKey(),
                'session_tenant' => $request->session()->get('tenant_id')
            ]);
            
            $this->forceLogout($request); // Forzar logout por inconsistencia de seguridad
            
            return abort(403, 'Inconsistencia de sesión detectada.');
        }

        return $next($request); // Continúa con la cadena de middleware
    }

    /**
     * Resuelve el tenant para el usuario actual con cache
     * 
     * @param User $user Usuario autenticado
     * @return Tenant|null Instancia del tenant o null si no tiene acceso
     */
    private function resolveTenantForUser(User $user): ?Tenant
    {
        $tenantId = $user->getCurrentTenantId(); // Obtiene ID del tenant actual del usuario

        if (! $tenantId) {
            return null;
        }

        // Cache Tagging (Opcional): Si usas Redis/Memcached, tags permiten limpiar caché por usuario específico
        // return Cache::tags(['tenancy', "user:{$user->getKey()}"])->remember(...
        return Cache::remember(
            self::CACHE_PREFIX . "{$user->getKey()}:{$tenantId}", // Clave única de cache
            self::CACHE_TTL,                                      // Tiempo de vida del cache
            fn () => $user->tenants()                            // Consulta la relación de tenants
                ->where($user->tenants()->getRelated()->getQualifiedKeyName(), $tenantId) // Filtra por ID
                ->first()                                        // Obtiene el primer resultado
        );
    }

    /**
     * Asegura la consistencia del tenant en la sesión
     * 
     * @param Request $request Solicitud HTTP actual
     * @param Tenant $tenant Tenant que debe estar activo
     * @return bool True si la sesión es consistente, false si hay discrepancia
     */
    private function ensureSessionConsistency(Request $request, Tenant $tenant): bool
    {
        $sessionKey = config(self::SESSION_KEY_CONFIG, 'tenant_id'); // Obtiene clave de sesión configurada
        $store = $request->session();                                // Obtiene store de sesión
        
        $current = $store->get($sessionKey) ?? $store->get('tenant_id'); // Lee tenant actual de sesión

        if ($current !== null && (string) $current !== (string) $tenant->getKey()) {
            return false; // Hubo discrepancia entre sesión y tenant resuelto
        }

        $store->put($sessionKey, $tenant->getKey());  // Actualiza clave de sesión configurada
        $store->put('tenant_id', $tenant->getKey());  // Actualiza clave estándar

        return true; // Sesión consistente
    }

    /**
     * Fuerza logout completo del usuario por inconsistencia de seguridad
     * 
     * @param Request $request Solicitud HTTP actual
     */
    private function forceLogout(Request $request): void
    {
        // Invalidar primero, logout después para asegurar limpieza
        $request->session()->invalidate();    // Invalida toda la sesión
        $request->session()->regenerateToken(); // Regenera token CSRF
        Auth::logout();                      // Cierra sesión de autenticación
        Session::flush();                    // Limpia completamente la sesión
    }

    /**
     * Registra evento de seguridad relacionado con tenancy
     * 
     * @param string $event Nombre del evento de seguridad
     * @param Request $request Solicitud HTTP relacionada
     * @param array $context Información adicional del contexto
     */
    private function logSecurityEvent(string $event, Request $request, array $context): void
    {
        Log::warning("[Tenancy] Security Alert: {$event}", array_merge($context, [ // Registra alerta
            'ip' => $request->ip(),                           // Dirección IP del cliente
            'url' => $request->fullUrl(),                     // URL completa de la solicitud
            'ua' => $request->userAgent(),                    // User Agent del cliente
        ]));
    }
}