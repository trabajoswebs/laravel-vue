<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Pipeline\Optimizer\Adapters;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use RuntimeException;

/**
 * Servicio para descargar archivos desde un sistema de archivos remoto a un archivo temporal local.
 *
 * Esta clase facilita la descarga de archivos desde un disco remoto (por ejemplo, S3, FTP, etc.)
 * hacia una ubicación local temporal, verificando que la cantidad de bytes descargados coincida
 * con el tamaño esperado, lo cual ayuda a garantizar la integridad del archivo.
 * Utiliza streams para una transferencia eficiente y gestionar adecuadamente los recursos.
 */
final class RemoteDownloader
{
    /**
     * Tipos MIME permitidos para descarga.
     */
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    /**
     * Límites de descarga HTTP.
     */
    private const STREAM_CHUNK_SIZE = 1024 * 1024; // 1MB
    private const STREAM_COPY_TIMEOUT = 300; // 5 minutos
    private const HTTP_MAX_BYTES = 25 * 1024 * 1024; // 25MB
    private const HTTP_REDIRECT_LIMIT = 3;
    private const HTTP_CHUNK_SIZE = 1024 * 1024; // 1MB
    private const HTTP_USER_AGENT = 'RemoteDownloader/1.0';
    private const HTTP_TIMEOUT = 15.0;
    private const HTTP_CONNECT_TIMEOUT = 5.0;
    private const HTTP_READ_TIMEOUT = 10.0;
    private const HTTP_ALLOWED_PORTS = [80, 443];
    private const METADATA_IPS = [
        '169.254.169.254',
        '::ffff:169.254.169.254',
        'fd00:ec2::254',
    ];
    private const HEAD_MAX_RETRIES = 2;

    /**
     * NOTA: La ruta temporal debe residir en un directorio seguro y no ser reubicable por usuarios no confiables.
     */

    /**
     * Constructor de la clase.
     *
     * @param FilesystemAdapter $disk Instancia del adaptador de sistema de archivos remoto.
     */
    public function __construct(
        private readonly FilesystemAdapter $disk,
        private readonly ?int $maxBytes = null,
        private readonly ?int $copyTimeout = null,
        private readonly ?int $redirectLimit = null,
    ) {}

    /**
     * Descarga un archivo remoto a una ubicación temporal local y verifica su tamaño.
     *
     * Lee un archivo desde el disco remoto como un stream y lo copia a un archivo local temporal.
     * Luego verifica que la cantidad de bytes copiados coincida con el tamaño esperado.
     *
     * @param string $relativePath Ruta del archivo remoto a descargar.
     * @param string $tempPath     Ruta local donde se guardará temporalmente el archivo.
     * @param int    $expectedBytes Cantidad esperada de bytes del archivo.
     *
     * @throws RuntimeException Si ocurre un error al leer el archivo remoto, abrir el archivo local,
     *                          copiar los datos o si el tamaño descargado no coincide con el esperado.
     *
     * @return int Cantidad de bytes realmente copiados.
     */
    public function download(string $relativePath, string $tempPath, int $expectedBytes): int
    {
        // Validar que el tamaño esperado sea válido
        if ($expectedBytes <= 0) {
            throw new RuntimeException('expected_bytes_invalid');
        }

        // Validar que la ruta no esté vacía
        if ($relativePath === '') {
            throw new RuntimeException('relative_path_invalid');
        }

        // Si es una URL HTTP, usar el método específico para descargas HTTP
        if ($this->isHttpUrl($relativePath)) {
            return $this->downloadFromHttp($relativePath, $tempPath, $expectedBytes);
        }

        // Validar que la ruta no sea absoluta ni contenga caracteres peligrosos
        if (str_starts_with($relativePath, '/') || str_contains($relativePath, '..')) {
            throw new RuntimeException('relative_path_invalid');
        }

        // Abrir stream del archivo remoto
        $stream = $this->disk->readStream($relativePath);
        if ($stream === false || !\is_resource($stream)) {
            throw new RuntimeException('stream_read_failed');
        }

        // Abrir archivo local temporal para escritura
        $out = fopen($tempPath, 'wb');
        if ($out === false || !\is_resource($out)) {
            fclose($stream);
            throw new RuntimeException('tmp_open_failed');
        }
        $this->hardenTempFile($tempPath);

        // Establecer timeout en el stream de lectura
        if (\function_exists('stream_set_timeout')) {
            stream_set_timeout($stream, 30);
        }

        $copied = 0;
        $chunkSize = self::STREAM_CHUNK_SIZE;
        $copyTimeout = $this->getStreamCopyTimeout();
        $startedAt = time();

        try {
            // Leer y escribir en bloques
            while (!feof($stream)) {
                if ((time() - $startedAt) > $copyTimeout) {
                    throw new RuntimeException('stream_copy_timeout');
                }

                $buffer = fread($stream, $chunkSize);
                if ($buffer === false) {
                    throw new RuntimeException('stream_read_chunk_failed');
                }

                $length = strlen($buffer);
                if ($length === 0) {
                    $meta = stream_get_meta_data($stream);
                    if (!empty($meta['timed_out'])) {
                        throw new RuntimeException('stream_copy_timeout');
                    }
                    break;
                }

                $copied += $length;
                if ($copied > $expectedBytes) {
                    throw new RuntimeException('stream_copy_exceeded');
                }

                if (fwrite($out, $buffer) === false) {
                    throw new RuntimeException('stream_write_failed');
                }
            }
        } catch (\Throwable $exception) {
            // Eliminar archivo temporal en caso de error
            $this->cleanupTempFile($tempPath);
            throw $exception;
        } finally {
            // Cerrar ambos streams
            fclose($out);
            fclose($stream);
        }

        // Verificar tamaño en disco antes de validar cantidades
        try {
            $actualSize = $this->readTempFileSize($tempPath);
        } catch (\Throwable $e) {
            $this->cleanupTempFile($tempPath);
            throw $e;
        }

        if ($actualSize !== $copied) {
            $this->cleanupTempFile($tempPath);
            throw new RuntimeException('stream_copy_size_mismatch');
        }

        // Validar que se haya copiado algo
        if ($copied === 0) {
            $this->cleanupTempFile($tempPath);
            throw new RuntimeException('stream_copy_empty');
        }

        // Validar que el tamaño coincida
        if ($copied < $expectedBytes) {
            $this->cleanupTempFile($tempPath);
            throw new RuntimeException('stream_copy_incomplete');
        }

        // Validar el tipo MIME del archivo descargado
        try {
            $this->ensureMimeMatchesAllowed($tempPath, null, $relativePath);
        } catch (\Throwable $exception) {
            $this->cleanupTempFile($tempPath);
            throw $exception;
        }

        return (int) $copied;
    }

    /**
     * Determina si una cadena representa una URL HTTP/HTTPS.
     *
     * @param string $value Cadena a evaluar.
     * @return bool Verdadero si es una URL HTTP o HTTPS.
     */
    private function isHttpUrl(string $value): bool
    {
        $components = @\parse_url($value);
        if ($components === false) {
            return false;
        }

        $scheme = $components['scheme'] ?? null;
        return \is_string($scheme) && \in_array(strtolower($scheme), ['http', 'https'], true);
    }

    /**
     * Descarga un archivo remoto usando HTTP(S) con defensas anti-SSRF.
     *
     * @param string $url URL remota a descargar.
     * @param string $tempPath Ruta local temporal donde guardar.
     * @param int $expectedBytes Tamaño esperado del archivo.
     * @return int Bytes copiados.
     * @throws RuntimeException Si ocurre un error en la descarga o validación.
     */
    private function downloadFromHttp(string $url, string $tempPath, int $expectedBytes): int
    {
        // Resolver la URL objetivo
        $target = $this->resolveHttpTarget($url);

        // Seguir redirecciones HEAD manualmente para aplicar defensas
        [$finalTarget, $head] = $this->followHeadRedirects($target);

        // Extraer y validar el tipo MIME desde la respuesta HEAD
        $mime = $this->extractMime($head);
        if ($mime === null || !\in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new RuntimeException('http_head_mime_blocked');
        }

        // Extraer y validar el tamaño del archivo
        $contentLength = $this->extractContentLength($head);
        if ($contentLength <= 0) {
            throw new RuntimeException('http_head_length_invalid');
        }
        if ($contentLength > $this->getMaxBytes()) {
            throw new RuntimeException('http_head_length_exceeds_limit');
        }

        if ($expectedBytes !== $contentLength) {
            throw new RuntimeException('expected_bytes_mismatch');
        }

        // Abrir archivo temporal para escritura
        $out = fopen($tempPath, 'wb');
        if ($out === false || !\is_resource($out)) {
            throw new RuntimeException('tmp_open_failed');
        }
        $this->hardenTempFile($tempPath);

        $binding = $finalTarget['binding'];

        // Hacer petición GET al host resuelto para evitar SSRF
        $response = $this->httpClient(true, $binding)->get($finalTarget['url']);
        if ($response->failed()) {
            fclose($out);
            $this->cleanupTempFile($tempPath);
            throw new RuntimeException('http_get_failed');
        }

        $body = $response->toPsrResponse()->getBody();
        $copied = 0;
        $copyTimeout = $this->getStreamCopyTimeout();
        $startedAt = time();

        try {
            // Copiar el cuerpo de la respuesta al archivo temporal
            while (!$body->eof()) {
                if ((time() - $startedAt) > $copyTimeout) {
                    throw new RuntimeException('http_stream_timeout');
                }

                $chunk = $body->read(self::HTTP_CHUNK_SIZE);
                if ($chunk === '') {
                    continue;
                }

                $length = \strlen($chunk);
                if ($length === 0) {
                    continue;
                }

                $copied += $length;
                if ($copied > $contentLength) {
                    throw new RuntimeException('http_stream_exceeded_length');
                }

                if (fwrite($out, $chunk) === false) {
                    throw new RuntimeException('stream_write_failed');
                }
            }
        } catch (\Throwable $exception) {
            fclose($out);
            $body->close();
            $this->cleanupTempFile($tempPath);
            throw $exception;
        }

        fclose($out);
        $body->close();

        // Verificar nuevamente el tamaño del archivo en disco
        try {
            $actualSize = $this->readTempFileSize($tempPath);
        } catch (\Throwable $e) {
            $this->cleanupTempFile($tempPath);
            throw $e;
        }

        if ($actualSize !== $copied) {
            $this->cleanupTempFile($tempPath);
            throw new RuntimeException('http_stream_size_mismatch');
        }

        // Validar que el tamaño coincida
        if ($copied !== $contentLength) {
            $this->cleanupTempFile($tempPath);
            throw new RuntimeException('http_stream_length_mismatch');
        }

        // Validar el tipo MIME del archivo descargado
        try {
            $this->ensureMimeMatchesAllowed($tempPath, $mime, $url);
        } catch (\Throwable $exception) {
            $this->cleanupTempFile($tempPath);
            throw $exception;
        }

        return $copied;
    }

    /**
     * Normaliza y valida la URL de entrada.
     *
     * @param string $url URL a validar y normalizar.
     * @param array<string, array<int, string>> $hostIpCache Cache opcional de IPs ya validadas.
     * @return array Información de la URL resuelta.
     * @throws RuntimeException Si la URL no es válida o no cumple las restricciones.
     */
    private function resolveHttpTarget(string $url, array $hostIpCache = []): array
    {
        try {
            $uri = new Uri($url);
        } catch (\InvalidArgumentException) {
            throw new RuntimeException('http_url_invalid');
        }

        $scheme = strtolower($uri->getScheme() ?? '');
        if (!\in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('http_scheme_not_allowed');
        }

        if ($uri->getFragment() !== '') {
            throw new RuntimeException('http_url_fragment_not_allowed');
        }

        if ($uri->getUserInfo() !== '') {
            throw new RuntimeException('http_url_credentials_not_allowed');
        }

        $host = $uri->getHost();
        if (!\is_string($host) || $host === '') {
            throw new RuntimeException('http_host_invalid');
        }

        $port = $uri->getPort();
        if ($port === null) {
            $port = $scheme === 'https' ? 443 : 80;
        }

        if (!\in_array($port, self::HTTP_ALLOWED_PORTS, true)) {
            throw new RuntimeException('http_port_not_allowed');
        }

        $hostKey = strtolower($host);
        if (isset($hostIpCache[$hostKey])) {
            $ips = $hostIpCache[$hostKey];
        } else {
            $ips = $this->assertHostAllowed($host);
        }

        if ($ips === []) {
            throw new RuntimeException('dns_resolution_failed');
        }

        if ($uri->getPath() === '') {
            $uri = $uri->withPath('/');
        }

        $defaultPort = $scheme === 'https' ? 443 : 80;
        if ($port === $defaultPort) {
            $uri = $uri->withPort(null);
        }

        return [
            'url' => (string) $uri,
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'ips' => $ips,
        ];
    }

    /**
     * Sigue redirecciones HEAD manualmente para aplicar defensas en cada salto.
     *
     * @param  array{url:string,scheme:string,host:string,port:int,ips:array<int,string>}  $target
     * @return array{0:array{url:string,scheme:string,host:string,port:int,ips:array<int,string>,binding:array{host:string,port:int,ip:string}},1:Response}
     * @throws RuntimeException Si se excede el límite de redirecciones o falla la petición.
     */
    private function followHeadRedirects(array $target): array
    {
        $current = $target;
        $redirects = 0;
        $hostIpCache = [
            strtolower($current['host']) => $current['ips'],
        ];

        while (true) {
            [$response, $binding] = $this->performHeadRequest($current);
            $status = $response->status();

            if ($status >= 400) {
                throw new RuntimeException('http_head_failed');
            }

            if (!\in_array($status, [301, 302, 303, 307, 308], true)) {
                $current['binding'] = $binding;
                return [$current, $response];
            }

            $location = $response->header('Location');
            if (!\is_string($location) || $location === '') {
                throw new RuntimeException('http_redirect_location_missing');
            }

            if (++$redirects > $this->getRedirectLimit()) {
                throw new RuntimeException('http_redirect_limit_exceeded');
            }

            $nextUrl = $this->resolveRedirectUrl($current['url'], $location);
            $current = $this->resolveHttpTarget($nextUrl, $hostIpCache);
            $hostIpCache[strtolower($current['host'])] = $current['ips'];
        }
    }

    /**
     * Realiza una petición HEAD a la URL objetivo y devuelve la respuesta y el binding.
     *
     * @param  array{url:string,scheme:string,host:string,port:int,ips:array<int,string>}  $target
     * @return array{0:Response,1:array{host:string,port:int,ip:string}}
     * @throws RuntimeException Si no se puede conectar a ninguna IP.
     */
    private function performHeadRequest(array $target): array
    {
        $lastException = null;

        foreach ($target['ips'] as $ip) {
            for ($attempt = 0; $attempt <= self::HEAD_MAX_RETRIES; $attempt++) {
                $binding = [
                    'host' => $target['host'],
                    'port' => $target['port'],
                    'ip'   => $ip,
                ];

                try {
                    $response = $this->httpClient(false, $binding)->head($target['url']);
                    return [$response, $binding];
                } catch (ConnectionException $exception) {
                    $lastException = $exception;

                    if ($attempt < self::HEAD_MAX_RETRIES) {
                        usleep(100000 * (2 ** $attempt));
                        continue;
                    }
                }
            }
        }

        throw new RuntimeException('http_head_unreachable', 0, $lastException);
    }

    /**
     * Extrae y normaliza el MIME desde la respuesta HEAD.
     *
     * @param Response $response Respuesta HTTP.
     * @return string|null Tipo MIME o null si no se encuentra.
     */
    private function extractMime(Response $response): ?string
    {
        $contentType = $response->header('Content-Type');
        if (!\is_string($contentType) || $contentType === '') {
            return null;
        }

        $semicolonPosition = strpos($contentType, ';');
        $mime = $semicolonPosition === false ? trim($contentType) : trim(substr($contentType, 0, $semicolonPosition));

        return $mime !== '' ? strtolower($mime) : null;
    }

    /**
     * Obtiene y valida el Content-Length de la respuesta HEAD.
     *
     * @param Response $response Respuesta HTTP.
     * @return int Tamaño del contenido.
     * @throws RuntimeException Si el header no es válido.
     */
    private function extractContentLength(Response $response): int
    {
        $header = $response->header('Content-Length');
        if (!\is_string($header) || $header === '') {
            throw new RuntimeException('http_head_length_missing');
        }

        if (!ctype_digit($header)) {
            throw new RuntimeException('http_head_length_invalid');
        }

        $maxDigits = strlen((string) PHP_INT_MAX);
        if (strlen($header) > $maxDigits) {
            throw new RuntimeException('http_head_length_too_large');
        }

        $length = filter_var($header, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($length === false) {
            throw new RuntimeException('http_head_length_invalid');
        }

        return $length;
    }

    /**
     * Verifica que el host no resuelva a IPs internas, loopback o link-local.
     *
     * @param string $host Nombre de host a resolver.
     * @return array Lista de IPs resueltas.
     * @throws RuntimeException Si el host no es válido o resuelve a IPs prohibidas.
     */
    private function assertHostAllowed(string $host): array
    {
        if ($host === '') {
            throw new RuntimeException('http_host_invalid');
        }

        // IPv6 literal viene entre corchetes.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = trim($host, '[]');
        }

        if ($this->isIpLiteral($host)) {
            $this->assertIpAllowed($host);
            return [$host];
        }

        $records = $this->resolveDns($host);
        if ($records === []) {
            throw new RuntimeException('dns_resolution_failed');
        }

        foreach ($records as $ip) {
            $this->assertIpAllowed($ip);
        }

        return $records;
    }

    /**
     * Resuelve registros A y AAAA para un host.
     *
     * @param string $host Nombre de host a resolver.
     * @return array<int, string> Lista de IPs resueltas.
     */
    private function resolveDns(string $host): array
    {
        $ips = [];
        $type = DNS_A;
        if (\defined('DNS_AAAA')) {
            $type |= DNS_AAAA;
        }

        $records = @dns_get_record($host, $type);
        if ($records === false) {
            return [];
        }

        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }

    /**
     * Verifica que una IP no pertenezca a rangos bloqueados.
     *
     * @param string $ip Dirección IP a validar.
     * @throws RuntimeException Si la IP no es válida o está prohibida.
     */
    private function assertIpAllowed(string $ip): void
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new RuntimeException('dns_ip_invalid');
        }

        $packed = @inet_pton($ip);
        if ($packed !== false && strlen($packed) === 16) {
            $mappedPrefix = str_repeat("\x00", 10)."\xff\xff";
            if (strncmp($packed, $mappedPrefix, 12) === 0) {
                $ipv4 = inet_ntop(substr($packed, 12));
                if ($ipv4 === false) {
                    throw new RuntimeException('dns_ip_blocked');
                }

                $this->assertIpAllowed($ipv4);
                return;
            }
        }

        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | $flags) &&
            !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | $flags)) {
            throw new RuntimeException('dns_ip_blocked');
        }

        if ($this->isLinkLocal($ip)) {
            throw new RuntimeException('dns_ip_blocked');
        }

        if (\in_array($ip, self::METADATA_IPS, true)) {
            throw new RuntimeException('dns_ip_blocked');
        }
    }

    /**
     * Determina si una IP es link-local (IPv4 169.254/16 o IPv6 fe80::/10).
     *
     * @param string $ip Dirección IP a verificar.
     * @return bool Verdadero si es link-local.
     */
    private function isLinkLocal(string $ip): bool
    {
        if (str_contains($ip, ':')) {
            $binary = @inet_pton($ip);
            if ($binary === false || strlen($binary) !== 16) {
                return false;
            }

            $first = ord($binary[0]);
            $second = ord($binary[1]);

            // IPv6 link-local: fe80::/10
            return $first === 0xFE && ($second & 0xC0) === 0x80;
        }

        // IPv4 link-local: 169.254.0.0/16
        return str_starts_with($ip, '169.254.');
    }

    /**
     * Construye las opciones base para peticiones HTTP.
     *
     * @param bool $stream Indica si se debe usar streaming.
     * @return array Opciones de configuración para la petición HTTP.
     */
    private function httpOptions(bool $stream = false): array
    {
        $options = [
            'connect_timeout' => self::HTTP_CONNECT_TIMEOUT,
            'timeout' => self::HTTP_TIMEOUT,
            'read_timeout' => self::HTTP_READ_TIMEOUT,
            'http_errors' => false,
            'allow_redirects' => false,
        ];

        if ($stream) {
            $options['stream'] = true;
        }

        return $options;
    }

    /**
     * Comprueba que el MIME detectado en disco coincide con los permitidos y opcionalmente con el esperado.
     *
     * @param string $path Ruta del archivo local.
     * @param string|null $expectedMime MIME esperado (opcional).
     * @param string|null $guessPath Ruta alternativa para deducir MIME por extensión.
     * @throws RuntimeException Si el MIME no es válido o no coincide.
     */
    private function ensureMimeMatchesAllowed(string $path, ?string $expectedMime, ?string $guessPath = null): void
    {
        $detectedMime = $this->detectMimeFromFile($path);
        $guessedMime = $this->guessMimeFromExtension($guessPath ?? $path);

        if ($detectedMime === null) {
            $detectedMime = $guessedMime;
        }

        if ($detectedMime === null) {
            throw new RuntimeException('mime_detection_failed');
        }

        if ($expectedMime !== null && $detectedMime !== $expectedMime) {
            if ($guessedMime === $expectedMime) {
                $detectedMime = $expectedMime;
            } else {
                throw new RuntimeException('http_download_mime_mismatch');
            }
        }

        if (!\in_array($detectedMime, self::ALLOWED_MIMES, true)) {
            if ($guessedMime !== null && \in_array($guessedMime, self::ALLOWED_MIMES, true)) {
                $detectedMime = $guessedMime;
            } else {
                throw new RuntimeException('mime_not_allowed');
            }
        }
    }

    /**
     * Detecta el MIME real de un archivo usando finfo.
     *
     * @param string $path Ruta del archivo local.
     * @return string|null Tipo MIME detectado o null si falla.
     */
    private function detectMimeFromFile(string $path): ?string
    {
        if (!function_exists('finfo_open')) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }

        try {
            $mime = finfo_file($finfo, $path);
        } finally {
            finfo_close($finfo);
        }

        if (!is_string($mime) || $mime === '') {
            return null;
        }

        $semicolon = strpos($mime, ';');
        if ($semicolon !== false) {
            $mime = substr($mime, 0, $semicolon);
        }

        return strtolower(trim($mime));
    }

    /**
     * Obtiene un MIME permitido usando la extensión del archivo como pista.
     *
     * @param string $path Ruta del archivo local.
     * @return string|null MIME permitido o null si no se pudo deducir.
     */
    private function guessMimeFromExtension(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => null,
        };
    }

    /**
     * Crea instancia de cliente HTTP con headers y opciones por defecto.
     *
     * @param bool $stream Indica si se debe usar streaming.
     * @param array|null $binding Información de binding para evitar SSRF.
     * @return PendingRequest Cliente HTTP configurado.
     * @throws RuntimeException Si no se puede usar CURLOPT_RESOLVE.
     */
    private function httpClient(bool $stream = false, ?array $binding = null): PendingRequest
    {
        $options = $this->httpOptions($stream);

        if ($binding !== null && isset($binding['host'], $binding['port'], $binding['ip'])) {
            $entry = $this->formatCurlResolveEntry(
                $binding['host'],
                (int) $binding['port'],
                $binding['ip']
            );

            if (!\defined('CURLOPT_RESOLVE')) {
                throw new RuntimeException('curl_resolve_not_supported');
            }

            $options['curl'][CURLOPT_RESOLVE] = [$entry];
        }

        $request = Http::withOptions($options)
            ->withHeaders([
                'User-Agent' => self::HTTP_USER_AGENT,
                'Accept' => '*/*',
            ]);

        if ($binding !== null && isset($binding['host']) && !$this->isIpLiteral($binding['host'])) {
            $hostHeader = $this->sanitizeHostHeader($binding['host']);
            $request = $request->withHeaders(['Host' => $hostHeader]);
        }

        return $request;
    }

    /**
     * Resuelve la URL absoluta de un Location de redirección.
     *
     * @param string $baseUrl URL base.
     * @param string $location Cabecera Location.
     * @return string URL absoluta resuelta.
     */
    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        $sanitizedLocation = trim($location);
        if ($sanitizedLocation === '') {
            throw new RuntimeException('http_redirect_location_missing');
        }

        try {
            $base = new Uri($baseUrl);
            $target = new Uri($sanitizedLocation);
            $resolved = UriResolver::resolve($base, $target);
        } catch (\InvalidArgumentException) {
            throw new RuntimeException('http_redirect_invalid');
        }

        $scheme = strtolower($resolved->getScheme() ?? '');
        if (!\in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('http_redirect_scheme_not_allowed');
        }

        if ($resolved->getFragment() !== '') {
            throw new RuntimeException('http_redirect_fragment_not_allowed');
        }

        return (string) $resolved;
    }

    /**
     * Verifica si una cadena es una IP literal.
     *
     * @param string $host Host a evaluar.
     * @return bool Verdadero si es una IP.
     */
    private function isIpLiteral(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Formatea una entrada de resolución para CURLOPT_RESOLVE.
     *
     * @param string $host Host a resolver.
     * @param int $port Puerto.
     * @param string $ip IP a asociar.
     * @return string Entrada formateada.
     */
    private function formatCurlResolveEntry(string $host, int $port, string $ip): string
    {
        $ipFormatted = str_contains($ip, ':') ? "[{$ip}]" : $ip;

        return "{$host}:{$port}:{$ipFormatted}";
    }

    private function hardenTempFile(string $path): void
    {
        if ($path === '' || !file_exists($path) || !\function_exists('chmod')) {
            return;
        }

        @chmod($path, 0600);
    }

    private function cleanupTempFile(string $path): void
    {
        if ($path === '' || !is_file($path)) {
            return;
        }

        if (@\unlink($path) === false) {
            Log::warning('RemoteDownloader failed to remove temp file', [
                'path' => $path,
            ]);
        }
    }

    private function sanitizeHostHeader(string $host): string
    {
        $trimmed = trim($host);
        if ($trimmed === '') {
            throw new RuntimeException('http_host_invalid');
        }

        if (!preg_match('/^[A-Za-z0-9.-]+$/', $trimmed)) {
            throw new RuntimeException('http_host_invalid_chars');
        }

        return $trimmed;
    }

    private function getMaxBytes(): int
    {
        return $this->maxBytes ?? self::HTTP_MAX_BYTES;
    }

    private function getStreamCopyTimeout(): int
    {
        return $this->copyTimeout ?? self::STREAM_COPY_TIMEOUT;
    }

    private function getRedirectLimit(): int
    {
        return $this->redirectLimit ?? self::HTTP_REDIRECT_LIMIT;
    }

    private function readTempFileSize(string $path): int
    {
        $size = @filesize($path);
        if ($size === false) {
            throw new RuntimeException('filesize_check_failed');
        }

        return (int) $size;
    }
}
