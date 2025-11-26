<?php

declare(strict_types=1);

namespace App\Domain\Security;

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
    /**
     * Genera una firma para límites generales basada en IP hash.
     *
     * Este método crea una clave de rate limiting que se basa únicamente
     * en la IP del cliente y un scope específico, útil para límites
     * genéricos que no requieren contexto adicional.
     *
     * @param Request $request Solicitud HTTP que contiene la IP
     * @param string  $scope   Scope específico para diferenciar el tipo de límite
     * @return string          Clave única para el rate limiter en formato "scope:hash_ip"
     */
    public function forIpScope(Request $request, string $scope): string
    {
        return $this->buildSignature($scope, $this->hashIp($request->ip())) ;
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
            // Si hay usuario autenticado, usar su ID para el rate limiting
            return $this->buildSignature('login:user', (string) $user->getAuthIdentifier());
        }

        // Si no hay usuario autenticado, usar la IP
        return $this->buildSignature('login:ip', $this->hashIp($request->ip()));
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
        return $this->buildSignature('general:' . $routeFingerprint, $this->hashIp($request->ip()));
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
        return $this->buildSignature('api', $this->hashIp($request->ip()));
    }

    /**
     * Convierte una IP en su hash SHA256 para protección de privacidad.
     *
     * Este método aplica hashing a las direcciones IP para proteger
     * la privacidad de los usuarios mientras mantiene la capacidad
     * de identificar de forma única a los clientes para rate limiting.
     *
     * @param ?string $ip Dirección IP a hashear (puede ser null)
     * @return string     Hash SHA256 de la IP
     */
    private function hashIp(?string $ip): string
    {
        return hash('sha256', (string) $ip);
    }

    /**
     * Construye una firma de rate limiting combinando scope e identificador.
     *
     * Este método auxiliar crea la clave final para el rate limiter
     * concatenando el scope y el identificador con un separador.
     *
     * @param string $scope      Categoría o contexto del rate limiting
     * @param string $identifier Identificador único (hash de IP, ID de usuario, etc.)
     * @return string           Clave final para el rate limiter
     */
    private function buildSignature(string $scope, string $identifier): string
    {
        return $scope . ':' . $identifier;
    }
}
