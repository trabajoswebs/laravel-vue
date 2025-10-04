<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Class TrustProxies
 *
 * Centraliza la configuración de proxies de confianza para obtener la IP real del cliente.
 * De esta forma middlewares como PreventBruteForce pueden firmar las peticiones con datos fiables.
 *
 * Fuentes de configuración (opcional en `config/security.php`):
 *  - trusted_proxies.proxies: null|'*'|string|array<int,string>
 *  - trusted_proxies.headers: int|string|array<int,string>
 */
class TrustProxies extends Middleware
{
    /**
     * Tabla de alias a máscaras de cabeceras permitidas.
     */
    protected const HEADER_ALIASES = [
        'aws' => Request::HEADER_X_FORWARDED_AWS_ELB,
        'header_x_forwarded_aws_elb' => Request::HEADER_X_FORWARDED_AWS_ELB,
        'forwarded' => Request::HEADER_FORWARDED,
        'header_forwarded' => Request::HEADER_FORWARDED,
        'for' => Request::HEADER_X_FORWARDED_FOR,
        'header_x_forwarded_for' => Request::HEADER_X_FORWARDED_FOR,
        'host' => Request::HEADER_X_FORWARDED_HOST,
        'header_x_forwarded_host' => Request::HEADER_X_FORWARDED_HOST,
        'port' => Request::HEADER_X_FORWARDED_PORT,
        'header_x_forwarded_port' => Request::HEADER_X_FORWARDED_PORT,
        'proto' => Request::HEADER_X_FORWARDED_PROTO,
        'header_x_forwarded_proto' => Request::HEADER_X_FORWARDED_PROTO,
        'prefix' => Request::HEADER_X_FORWARDED_PREFIX,
        'header_x_forwarded_prefix' => Request::HEADER_X_FORWARDED_PREFIX,
    ];

    /**
     * Conjunto habitual de cabeceras.
     */
    protected const HEADER_PRESET_ALL = ['for', 'host', 'port', 'proto', 'prefix', 'aws'];

    /**
     * Máscara por defecto cuando la configuración es inválida o inexistente.
     */
    protected const DEFAULT_HEADER_MASK = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_PREFIX
        | Request::HEADER_X_FORWARDED_AWS_ELB;

    /**
     * Evita repetir logs de configuración.
     *
     * @var bool
     */
    protected static bool $configurationLogged = false;

    /**
     * Evita duplicar avisos sobre comodines en producción.
     *
     * @var bool
     */
    protected static bool $wildcardWarningLogged = false;

    /**
     * Lista de proxies confiables. Puede ser null, '*', o un array de IPs/CIDR.
     *
     * Ejemplos válidos:
     *  - null (no se confía en ningún proxy)
     *  - '*'  (se confía en todos; úsalo con cautela)
     *  - ['10.0.0.0/8', '192.168.0.0/16']
     *
     * @var array<int, string>|string|null
     */
    protected $proxies;

    /**
     * Máscara de cabeceras que se usarán para resolver la IP real.
     *
     * Combinación de constantes HEADER_* de Symfony (expuestas por Illuminate\Http\Request).
     *
     * @var int
     */
    protected $headers;

    /**
     * Constructor: carga y normaliza la configuración de proxies y cabeceras.
     *
     * Lee `security.trusted_proxies` y prepara `$proxies` y `$headers` para el middleware base.
     */
    public function __construct()
    {
        $config = config('security.trusted_proxies', []);

        $this->proxies = $this->resolveProxies($config['proxies'] ?? null);
        $this->headers = $this->resolveHeaders($config['headers'] ?? null);

        $this->logResolvedConfiguration();
    }

    /**
     * Normaliza la lista de proxies aceptando null, '*', string o array.
     *
     * Reglas:
     *  - null|''  → null (no hay proxies de confianza)
     *  - '*'      → '*'  (todos confiables; solo en redes controladas)
     *  - string   → se separa por comas y se trimmean los valores
     *  - array    → se asegura forma plana sin vacíos
     *
     * @param array<int,string>|string|null $value Valor crudo de configuración.
     * @return array<int,string>|string|null Lista normalizada o comodín/null.
     */
    protected function resolveProxies(array|string|null $value): array|string|null
    {
        if ($value === null || $value === '') {
            return null; // Sin proxies de confianza
        }

        if ($value === '*') {
            $this->warnAboutWildcard();

            return '*'; // Confiar en todos (peligroso si la red no está controlada)
        }

        $proxies = is_array($value)
            ? $value
            : $this->splitList((string) $value, '/\s*,\s*/', false);

        // Elimina vacíos, recorta espacios y reindexa el array
        $proxies = array_values(array_filter(array_map('trim', $proxies), static fn ($proxy) => $proxy !== ''));

        if ($proxies === []) {
            return null;
        }

        [$valid, $invalid] = $this->partitionProxies($proxies);

        if ($invalid !== []) {
            Log::warning('Ignorando proxies no válidos en la configuración de confianza.', [
                'invalid' => $invalid,
            ]);
        }

        if ($valid === []) {
            return null;
        }

        return $valid;
    }

    /**
     * Traduce la configuración a la máscara de cabeceras adecuada.
     *
     * Acepta:
     *  - int (máscara ya preparada)
     *  - string: tokens separados por "|", "," o espacios (case-insensitive)
     *  - array<string>: tokens sueltos
     *
     * Tokens válidos (alias → constante):
     *  - aws      → Request::HEADER_X_FORWARDED_AWS_ELB
     *  - forwarded→ Request::HEADER_FORWARDED
     *  - for      → Request::HEADER_X_FORWARDED_FOR
     *  - host     → Request::HEADER_X_FORWARDED_HOST
     *  - port     → Request::HEADER_X_FORWARDED_PORT
     *  - proto    → Request::HEADER_X_FORWARDED_PROTO
     *  - prefix   → Request::HEADER_X_FORWARDED_PREFIX
     *
     * Atajos:
     *  - "all" o "*" → usa el set completo habitual (for, host, port, proto, prefix, aws)
     *
     * Fallback por defecto: combina todas las cabeceras X-Forwarded-* y AWS ELB.
     *
     * @param mixed $value Valor crudo desde config.
     * @return int  Máscara combinada de constantes HEADER_*.
     */
    protected function resolveHeaders(mixed $value): int
    {
        if (is_numeric($value)) {
            return (int) $value; // Ya viene como máscara
        }

        $tokens = [];

        if (is_string($value) && $value !== '') {
            $tokens = $this->splitList($value, '/[|,\s]+/');
        } elseif (is_array($value)) {
            $tokens = array_map(static fn ($token) => strtolower(trim((string) $token)), $value);
        }

        if ($tokens === ['all'] || $tokens === ['*']) {
            $tokens = self::HEADER_PRESET_ALL;
        }

        $tokens = array_values(array_filter($tokens, static fn ($token) => $token !== ''));

        if ($tokens === []) {
            return self::DEFAULT_HEADER_MASK;
        }

        $mask = 0;
        $invalid = [];

        foreach ($tokens as $token) {
            if (isset(self::HEADER_ALIASES[$token])) {
                $mask |= self::HEADER_ALIASES[$token]; // Combina banderas con OR a nivel de bit
                continue;
            }

            $invalid[] = $token;
        }

        if ($invalid !== []) {
            Log::warning('Ignorando cabeceras desconocidas en trusted proxies.', [
                'invalid' => $invalid,
            ]);
        }

        if ($mask > 0) {
            return $mask; // Se configuró al menos un token válido
        }

        return self::DEFAULT_HEADER_MASK;
    }

    /**
     * Registra la configuración aplicada una única vez por proceso.
     */
    protected function logResolvedConfiguration(): void
    {
        if (self::$configurationLogged) {
            return;
        }

        self::$configurationLogged = true;

        Log::info('Middleware de proxies de confianza inicializado.', [
            'proxies' => $this->proxies,
            'headers_mask' => $this->headers,
        ]);
    }

    /**
     * Realiza split sobre un string usando un patrón de delimitadores.
     */
    protected function splitList(string $value, string $pattern, bool $lowercase = true): array
    {
        $subject = $lowercase ? strtolower($value) : $value;
        $parts = preg_split($pattern, $subject, -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : $parts;
    }

    /**
     * Divide los proxies entre válidos e inválidos.
     *
     * @param array<int,string> $proxies
     * @return array{0: array<int,string>, 1: array<int,string>}
     */
    protected function partitionProxies(array $proxies): array
    {
        $valid = [];
        $invalid = [];

        foreach ($proxies as $proxy) {
            if ($this->isValidProxy($proxy)) {
                $valid[] = $proxy;
                continue;
            }

            $invalid[] = $proxy;
        }

        return [$valid, $invalid];
    }

    /**
     * Verifica si un proxy es una IP o CIDR válida.
     */
    protected function isValidProxy(string $proxy): bool
    {
        if (filter_var($proxy, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        if (! str_contains($proxy, '/')) {
            return false;
        }

        [$ip, $mask] = explode('/', $proxy, 2);
        $ip = trim($ip);
        $mask = trim($mask);

        if ($ip === '' || $mask === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $maxRange = str_contains($ip, ':') ? 128 : 32;

        $mask = filter_var($mask, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 0,
                'max_range' => $maxRange,
            ],
        ]);

        return $mask !== false;
    }

    /**
     * Muestra un warning si se confía en todos los proxies en producción.
     */
    protected function warnAboutWildcard(): void
    {
        if (self::$wildcardWarningLogged || ! app()->environment('production')) {
            return;
        }

        self::$wildcardWarningLogged = true;

        Log::warning('Se ha habilitado el comodín "*" para proxies confiables en producción. Verifica que tu red esté protegida.');
    }
}
