<?php

namespace App\Http\Middleware;

use App\Modules\Uploads\Pipeline\Security\Logging\MediaLogSanitizer;
use App\Modules\Uploads\Pipeline\Security\Logging\MediaSecurityLogger;
// Importamos las clases necesarias para el middleware
use Illuminate\Http\Middleware\TrustProxies as Middleware; // Clase base de Laravel para confianza en proxies
use Illuminate\Http\Request; // Clase para manejar las solicitudes HTTP

/**
 * Class TrustProxies
 *
 * Este middleware centraliza la configuración de proxies de confianza para obtener la IP real del cliente.
 * Esto es crucial para que otros middlewares como PreventBruteForce puedan firmar las peticiones 
 * con datos fiables de la IP original del usuario.
 *
 * La configuración se puede definir opcionalmente en `config/security.php`:
 *  - trusted_proxies.proxies: puede ser null, '*', string o array<int,string>
 *  - trusted_proxies.headers: puede ser int, string o array<int,string>
 */
class TrustProxies extends Middleware
{
    private ?MediaSecurityLogger $securityLogger = null;
    private ?MediaLogSanitizer $logSanitizer = null;

    /**
     * Tabla de alias a máscaras de cabeceras permitidas.
     * 
     * Este array define atajos legibles para las constantes de cabeceras HTTP 
     * que Laravel utiliza para identificar la IP real del cliente.
     * Por ejemplo, 'for' se traduce a Request::HEADER_X_FORWARDED_FOR.
     * Esto facilita la configuración sin tener que usar constantes complejas directamente.
     */
    protected const HEADER_ALIASES = [
        'aws' => Request::HEADER_X_FORWARDED_AWS_ELB, // Cabecera usada por AWS ELB
        'header_x_forwarded_aws_elb' => Request::HEADER_X_FORWARDED_AWS_ELB, // Alias alternativo
        'forwarded' => Request::HEADER_FORWARDED, // Cabecera Forwarded estándar
        'header_forwarded' => Request::HEADER_FORWARDED, // Alias alternativo
        'for' => Request::HEADER_X_FORWARDED_FOR, // Cabecera X-Forwarded-For común
        'header_x_forwarded_for' => Request::HEADER_X_FORWARDED_FOR, // Alias alternativo
        'host' => Request::HEADER_X_FORWARDED_HOST, // Cabecera X-Forwarded-Host
        'header_x_forwarded_host' => Request::HEADER_X_FORWARDED_HOST, // Alias alternativo
        'port' => Request::HEADER_X_FORWARDED_PORT, // Cabecera X-Forwarded-Port
        'header_x_forwarded_port' => Request::HEADER_X_FORWARDED_PORT, // Alias alternativo
        'proto' => Request::HEADER_X_FORWARDED_PROTO, // Cabecera X-Forwarded-Proto
        'header_x_forwarded_proto' => Request::HEADER_X_FORWARDED_PROTO, // Alias alternativo
        'prefix' => Request::HEADER_X_FORWARDED_PREFIX, // Cabecera X-Forwarded-Prefix
        'header_x_forwarded_prefix' => Request::HEADER_X_FORWARDED_PREFIX, // Alias alternativo
    ];

    /**
     * Conjunto habitual de cabeceras.
     * 
     * Define un atajo 'all' o '*' que representa todas las cabeceras comunes 
     * utilizadas para obtener la IP real del cliente detrás de un proxy.
     */
    protected const HEADER_PRESET_ALL = ['for', 'host', 'port', 'proto', 'prefix', 'aws'];

    /**
     * Máscara por defecto cuando la configuración es inválida o inexistente.
     * 
     * Si no se puede determinar una configuración válida de cabeceras, 
     * este valor se usará como fallback seguro.
     * Combina las cabeceras X-Forwarded-* más comunes y AWS ELB.
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
     * Variable estática para asegurar que solo se registre la configuración 
     * del middleware una vez por ciclo de vida de la aplicación, 
     * evitando logs redundantes.
     *
     * @var bool
     */
    protected static bool $configurationLogged = false;

    /**
     * Evita duplicar avisos sobre comodines en producción.
     * 
     * Variable estática para asegurar que solo se registre el aviso 
     * sobre el uso de '*' para proxies de confianza una vez por ciclo de vida de la aplicación, 
     * especialmente en entornos de producción.
     *
     * @var bool
     */
    protected static bool $wildcardWarningLogged = false;

    /**
     * Lista de proxies confiables. Puede ser null, '*', o un array de IPs/CIDR.
     *
     * Define qué proxies se consideran confiables para que sus cabeceras 
     * (como X-Forwarded-For) sean tomadas en cuenta para determinar la IP real del cliente.
     * Ejemplos válidos:
     *  - null (no se confía en ningún proxy, se usa la IP directa)
     *  - '*'  (se confía en todos; úsalo con mucha cautela, solo en redes controladas)
     *  - ['10.0.0.0/8', '192.168.0.0/16'] (lista específica de proxies confiables)
     *
     * @var array<int, string>|string|null
     */
    protected $proxies;

    /**
     * Máscara de cabeceras que se usarán para resolver la IP real.
     *
     * Combinación de constantes HEADER_* de Symfony (expuestas por Illuminate\Http\Request).
     * Define qué cabeceras HTTP se deben inspeccionar para obtener la IP real del cliente.
     *
     * @var int
     */
    protected $headers;

    /**
     * Constructor: carga y normaliza la configuración de proxies y cabeceras.
     *
     * Este método se ejecuta al instanciar el middleware.
     * Lee la configuración desde `config/security.trusted_proxies` y prepara 
     * las propiedades `$proxies` y `$headers` para que el middleware base de Laravel las use.
     */
    public function __construct()
    {
        // Lee la configuración desde config/security.php, o usa un array vacío si no existe
        $config = config('security.trusted_proxies', []);

        // Resuelve y normaliza la lista de proxies confiables
        $this->proxies = $this->resolveProxies($config['proxies'] ?? null);

        // Resuelve y normaliza la máscara de cabeceras
        $this->headers = $this->resolveHeaders($config['headers'] ?? null);

        // Registra la configuración resuelta una sola vez
        $this->logResolvedConfiguration();
    }

    /**
     * Normaliza la lista de proxies aceptando null, '*', string o array.
     *
     * Este método convierte la configuración de proxies en un formato que Laravel puede entender.
     * Maneja diferentes tipos de entrada y los convierte a un array de IPs o un comodín.
     *
     * Reglas:
     *  - null|''  → null (no hay proxies de confianza, se ignora cualquier proxy)
     *  - '*'      → '*'  (todos los proxies son confiables; solo en redes controladas)
     *  - string   → se separa por comas y se trimmean los valores (ej: '10.0.0.1, 10.0.0.2')
     *  - array    → se asegura forma plana sin vacíos (elimina entradas vacías)
     *
     * @param array<int,string>|string|null $value Valor crudo de configuración.
     * @return array<int,string>|string|null Lista normalizada o comodín/null.
     */
    protected function resolveProxies(array|string|null $value): array|string|null
    {
        // Si no hay valor o está vacío, no se confía en ningún proxy
        if ($value === null || $value === '') {
            return null; // Sin proxies de confianza
        }

        // Si se usa el comodín '*', confía en todos los proxies
        if ($value === '*') {
            // Emite un aviso si estamos en producción, ya que es riesgoso
            $this->warnAboutWildcard();

            return '*'; // Confiar en todos (peligroso si la red no está controlada)
        }

        // Si es un array, lo usamos directamente; si es string, lo dividimos por comas
        $proxies = is_array($value)
            ? $value
            : $this->splitList((string) $value, '/\s*,\s*/', false); // Dividir por comas con espacios

        // Elimina vacíos, recorta espacios y reindexa el array para asegurar índices numéricos
        $proxies = array_values(array_filter(array_map('trim', $proxies), static fn($proxy) => $proxy !== ''));

        // Si después de limpiar no hay proxies válidos, devolvemos null
        if ($proxies === []) {
            return null;
        }

        // Divide los proxies entre válidos e inválidos
        [$valid, $invalid] = $this->partitionProxies($proxies);

        // Si hay proxies inválidos, registramos un warning
        if ($invalid !== []) {
            $this->securityLogger()->warning('http.trust_proxies.invalid_proxies', [
                'invalid_count' => count($invalid),
                'invalid_hash' => $this->logSanitizer()->hashName(implode('|', $invalid)),
            ]);
        }

        // Si no hay proxies válidos, devolvemos null
        if ($valid === []) {
            return null;
        }

        // Devolvemos solo los proxies válidos
        return $valid;
    }

    /**
     * Traduce la configuración a la máscara de cabeceras adecuada.
     *
     * Este método convierte la configuración de cabeceras en una máscara entera 
     * que Laravel puede usar para determinar qué cabeceras inspeccionar.
     *
     * Acepta:
     *  - int (máscara ya preparada, se devuelve tal cual)
     *  - string: tokens separados por "|", "," o espacios (case-insensitive)
     *  - array<string>: tokens sueltos en un array
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
        // Si ya es un número (máscara), lo devolvemos directamente
        if (is_numeric($value)) {
            $mask = (int) $value;
            // Validar que sea una combinación válida de flags
            $maxMask = Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX
                | Request::HEADER_X_FORWARDED_AWS_ELB
                | Request::HEADER_FORWARDED;

            if ($mask > 0 && ($mask & ~$maxMask) === 0) {
                return $mask;
            }
            $this->securityLogger()->warning('http.trust_proxies.invalid_headers_mask', ['headers_mask' => $mask]);
            return self::DEFAULT_HEADER_MASK;
        }

        $tokens = []; // Array para almacenar los tokens de cabecera

        // Si es string, lo dividimos en tokens
        if (is_string($value) && $value !== '') {
            $tokens = $this->splitList($value, '/[|,\s]+/'); // Dividir por |, coma o espacios
        } elseif (is_array($value)) {
            // Si es array, normalizamos cada token
            $tokens = array_map(static fn($token) => strtolower(trim((string) $token)), $value);
        }

        // Si se usan los atajos 'all' o '*', usamos el conjunto predeterminado
        if ($tokens === ['all'] || $tokens === ['*']) {
            $tokens = self::HEADER_PRESET_ALL; // Conjunto completo de cabeceras comunes
        }

        // Elimina tokens vacíos
        $tokens = array_values(array_filter($tokens, static fn($token) => $token !== ''));

        // Si no hay tokens válidos, usamos la máscara por defecto
        if ($tokens === []) {
            return self::DEFAULT_HEADER_MASK;
        }

        $mask = 0; // Inicializamos la máscara a 0
        $invalid = []; // Array para almacenar tokens inválidos

        // Iteramos sobre los tokens y construimos la máscara
        foreach ($tokens as $token) {
            // Si el token es un alias conocido, lo añadimos a la máscara
            if (isset(self::HEADER_ALIASES[$token])) {
                $mask |= self::HEADER_ALIASES[$token]; // Combina banderas con OR a nivel de bit
                continue; // Pasamos al siguiente token
            }

            // Si no es un alias conocido, lo marcamos como inválido
            $invalid[] = $token;
        }

        // Si hay tokens inválidos, registramos un warning
        if ($invalid !== []) {
            $this->securityLogger()->warning('http.trust_proxies.unknown_header_aliases', [
                'invalid_count' => count($invalid),
                'headers_mask' => $mask,
            ]);
        }

        // Si se configuró al menos un token válido, devolvemos la máscara construida
        if ($mask > 0) {
            return $mask; // Se configuró al menos un token válido
        }

        // Si no se configuró ningún token válido, usamos la máscara por defecto
        return self::DEFAULT_HEADER_MASK;
    }

    /**
     * Registra la configuración aplicada una única vez por proceso.
     * 
     * Este método asegura que la configuración resuelta del middleware 
     * se registre en los logs solo una vez por ciclo de vida de la aplicación, 
     * evitando logs redundantes.
     */
    protected function logResolvedConfiguration(): void
    {
        // Si ya se registró la configuración, no lo hacemos de nuevo
        if (self::$configurationLogged) {
            return;
        }

        // Marcamos que ya se registró
        self::$configurationLogged = true;

        // Registramos la configuración resuelta
        $this->securityLogger()->info('http.trust_proxies.initialized', [
            'proxies_mode' => $this->proxiesMode(),
            'proxies_count' => is_array($this->proxies) ? count($this->proxies) : null,
            'headers_mask' => $this->headers,
        ]);
    }

    /**
     * Realiza split sobre un string usando un patrón de delimitadores.
     * 
     * Esta función auxiliar divide un string en partes usando una expresión regular.
     * Es útil para convertir cadenas como 'for,host,proto' en un array ['for', 'host', 'proto'].
     *
     * @param string $value String a dividir
     * @param string $pattern Expresión regular para dividir el string
     * @param bool $lowercase Si es true, convierte los resultados a minúsculas
     * @return array Array de partes resultantes del split
     */
    protected function splitList(string $value, string $pattern, bool $lowercase = true): array
    {
        // Aplica minúsculas si está habilitado
        $subject = $lowercase ? strtolower($value) : $value;

        // Divide el string usando la expresión regular
        $parts = preg_split($pattern, $subject, -1, PREG_SPLIT_NO_EMPTY);

        // Devuelve el array de partes, o un array vacío si falló el split
        return $parts === false ? [] : $parts;
    }

    /**
     * Divide los proxies entre válidos e inválidos.
     *
     * Este método verifica cada proxy en la lista y los clasifica en válidos e inválidos.
     * Un proxy es válido si es una IP válida (IPv4 o IPv6) o un bloque CIDR válido.
     *
     * @param array<int,string> $proxies Array de proxies a validar
     * @return array{0: array<int,string>, 1: array<int,string>} Array con dos elementos:
     *         - [0]: array de proxies válidos
     *         - [1]: array de proxies inválidos
     */
    protected function partitionProxies(array $proxies): array
    {
        $valid = [];   // Array para almacenar proxies válidos
        $invalid = []; // Array para almacenar proxies inválidos

        // Iteramos sobre cada proxy y lo validamos
        foreach ($proxies as $proxy) {
            // Si el proxy es válido, lo añadimos al array de válidos
            if ($this->isValidProxy($proxy)) {
                $valid[] = $proxy;
                continue; // Pasamos al siguiente proxy
            }

            // Si no es válido, lo añadimos al array de inválidos
            $invalid[] = $proxy;
        }

        // Devolvemos ambos arrays
        return [$valid, $invalid];
    }

    /**
     * Verifica si un proxy es una IP o CIDR válida.
     * 
     * Este método comprueba si un string representa una IP válida (IPv4 o IPv6) 
     * o un bloque CIDR (Classless Inter-Domain Routing) válido.
     * 
     * @param string $proxy String a validar (ej: '192.168.1.1' o '192.168.0.0/16')
     * @return bool true si el proxy es una IP o CIDR válido, false en caso contrario
     */
    protected function isValidProxy(string $proxy): bool
    {
        // Si es una IP válida (IPv4 o IPv6), es un proxy válido
        if (filter_var($proxy, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        // Si no contiene '/', no puede ser un CIDR
        if (! str_contains($proxy, '/')) {
            return false;
        }

        // Dividimos el CIDR en IP y máscara
        [$ip, $mask] = explode('/', $proxy, 2);
        $ip = trim($ip);   // Limpiamos espacios de la IP
        $mask = trim($mask); // Limpiamos espacios de la máscara

        // Si la IP o la máscara están vacías, o la IP no es válida, no es un CIDR válido
        if ($ip === '' || $mask === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // Determinamos el rango máximo según si es IPv4 o IPv6
        $maxRange = str_contains($ip, ':') ? 128 : 32; // 128 para IPv6, 32 para IPv4

        // Validamos que la máscara sea un número entero dentro del rango correcto
        $mask = filter_var($mask, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 0,      // Mínimo 0
                'max_range' => $maxRange, // Máximo depende de IPv4/IPv6
            ],
        ]);

        // Devolvemos true si la máscara es válida, false si no
        return $mask !== false;
    }

    /**
     * Muestra un warning si se confía en todos los proxies en producción.
     * 
     * Este método emite un aviso de seguridad si se está usando el comodín '*' 
     * para confiar en todos los proxies en un entorno de producción.
     * Esto es peligroso porque podría permitir que usuarios maliciosos falsifiquen 
     * su IP real simplemente añadiendo cabeceras X-Forwarded-For.
     */
    protected function warnAboutWildcard(): void
    {
        // Si ya se emitió el aviso o no estamos en producción, no hacemos nada
        if (self::$wildcardWarningLogged || ! app()->environment('production')) {
            return;
        }

        // Marcamos que ya se emitió el aviso
        self::$wildcardWarningLogged = true;

        // Registramos el aviso de seguridad
        $this->securityLogger()->warning('http.trust_proxies.wildcard_enabled_in_production');
    }

    private function securityLogger(): MediaSecurityLogger
    {
        return $this->securityLogger ??= app(MediaSecurityLogger::class);
    }

    private function logSanitizer(): MediaLogSanitizer
    {
        return $this->logSanitizer ??= app(MediaLogSanitizer::class);
    }

    private function proxiesMode(): string
    {
        if ($this->proxies === '*') {
            return 'wildcard';
        }

        if (is_array($this->proxies) && $this->proxies !== []) {
            return 'list';
        }

        return 'none';
    }
}
