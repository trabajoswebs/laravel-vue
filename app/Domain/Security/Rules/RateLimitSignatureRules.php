<?php

declare(strict_types=1);

namespace App\Domain\Security\Rules;

/**
 * Reglas para generar firmas únicas para sistemas de limitación de velocidad (rate limiting).
 * 
 * Crea identificadores consistentes para diferentes tipos de limitaciones basadas en
 * IP, usuario o tipo de operación, permitiendo aplicar límites específicos por contexto.
 */
final class RateLimitSignatureRules
{
    /**
     * Genera una firma para limitación de velocidad basada en IP y scope.
     *
     * @param string|null $ip Dirección IP del cliente
     * @param string $scope Contexto específico de la operación
     * @return string Firma única para rate limiting
     */
    public function forIpScope(?string $ip, string $scope): string
    {
        return $this->buildSignature($scope, $this->hashIp($ip));
    }

    /**
     * Genera una firma para limitación de velocidad en operaciones de login.
     *
     * Prioriza el ID de usuario si está disponible, de lo contrario usa IP.
     *
     * @param string|null $userId ID del usuario (si está autenticado)
     * @param string|null $ip Dirección IP del cliente
     * @return string Firma única para rate limiting de login
     */
    public function forLogin(?string $userId, ?string $ip): string
    {
        if ($userId !== null && $userId !== '') {
            return $this->buildSignature('login:user', $userId);
        }

        return $this->buildSignature('login:ip', $this->hashIp($ip));
    }

    /**
     * Genera una firma para limitación de velocidad general basada en IP y huella de ruta.
     *
     * @param string|null $ip Dirección IP del cliente
     * @param string $routeFingerprint Huella única de la ruta/endpoint
     * @return string Firma única para rate limiting general
     */
    public function forGeneral(?string $ip, string $routeFingerprint): string
    {
        return $this->buildSignature('general:' . $routeFingerprint, $this->hashIp($ip));
    }

    /**
     * Genera una firma para limitación de velocidad en APIs.
     *
     * @param string|null $ip Dirección IP del cliente
     * @return string Firma única para rate limiting de API
     */
    public function forApi(?string $ip): string
    {
        return $this->buildSignature('api', $this->hashIp($ip));
    }

    /**
     * Hashea una dirección IP para anonimizarla.
     *
     * @param string|null $ip Dirección IP a hashear
     * @return string Hash SHA-256 de la IP
     */
    private function hashIp(?string $ip): string
    {
        return hash('sha256', (string) $ip);
    }

    /**
     * Construye una firma combinando scope e identificador.
     *
     * @param string $scope Contexto de la operación
     * @param string $identifier Identificador único (hash de IP o ID de usuario)
     * @return string Firma completa para rate limiting
     */
    private function buildSignature(string $scope, string $identifier): string
    {
        return $scope . ':' . $identifier;
    }
}
