<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Security\Rules\RateLimitSignatureRules;
use Illuminate\Http\Request;

/**
 * Factoría para construir firmas de rate limiting consistentes
 *
 * Esta clase centraliza la lógica de creación de claves únicas para el rate limiting
 * de Laravel, asegurando consistencia en la generación de identificadores a través
 * de toda la aplicación. La factoría proporciona diferentes métodos para construir
 * firmas según el contexto (login, API, general), manteniendo una semántica uniforme
 * y protegiendo la privacidad de los usuarios mediante el hashing de IPs.
 *
 * Características principales:
 * - Generación de claves consistentes para rate limiting
 * - Protección de privacidad mediante hashing de IPs
 * - Diferenciación de contexto (login, API, general)
 * - Soporte para identificación por usuario autenticado o IP
 * - Normalización de rutas para agrupación de solicitudes similares
 *
 * Uso:
 * $factory = new RateLimitSignatureFactory();
 * $key = $factory->forLogin($request);
 * RateLimiter::hit($key, $decaySeconds);
 */
class RateLimitSignatureFactory
{
    public function __construct(
        private readonly RateLimitSignatureRules $rules,
    ) {}

    public function forIpScope(Request $request, string $scope): string
    {
        return $this->rules->forIpScope($request->ip(), $scope);
    }

    /**
     * Genera una firma específica para operaciones de login.
     *
     * Este método implementa una estrategia dual:
     * - Si hay un usuario autenticado, usa su ID para el rate limiting
     * - Si no hay usuario autenticado, usa la IP del cliente
     *
     * Esto permite proteger contra ataques de fuerza bruta tanto a nivel
     * de cuenta como a nivel de origen.
     *
     * @param Request $request Solicitud HTTP que puede contener un usuario autenticado
     * @return string          Clave única para rate limiting de login
     */
    public function forLogin(Request $request): string
    {
        $user = $request->user();

        if ($user !== null) {
            return $this->rules->forLogin((string) $user->getAuthIdentifier(), null);
        }

        return $this->rules->forLogin(null, $request->ip());
    }

    /**
     * Genera una firma para endpoints generales basada en IP y ruta.
     *
     * Este método crea claves específicas para rutas generales que incluyen
     * tanto la IP como una huella digital de la ruta (método + ruta normalizada)
     * para permitir límites más granulares por endpoint específico.
     *
     * @param Request $request         Solicitud HTTP actual
     * @param string  $routeFingerprint Huella digital de la ruta (método:ruta_normalizada)
     * @return string                  Clave única para rate limiting general
     */
    public function forGeneral(Request $request, string $routeFingerprint): string
    {
        return $this->rules->forGeneral($request->ip(), $routeFingerprint);
    }

    /**
     * Genera una firma para rutas de API basada en IP hash.
     *
     * Este método crea claves específicas para rutas de API, permitiendo
     * límites diferentes a las rutas web generales. Usa la IP hash para
     * proteger la privacidad del cliente mientras mantiene la capacidad
     * de limitar por origen.
     *
     * @param Request $request Solicitud HTTP de la API
     * @return string          Clave única para rate limiting de API
     */
    public function forApi(Request $request): string
    {
        return $this->rules->forApi($request->ip());
    }
}
