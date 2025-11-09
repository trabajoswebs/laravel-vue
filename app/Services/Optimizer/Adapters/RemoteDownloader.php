<?php

declare(strict_types=1);

namespace App\Services\Optimizer\Adapters;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
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
    private const HTTP_ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    private const HTTP_MAX_BYTES = 25 * 1024 * 1024; // 25MB
    private const HTTP_REDIRECT_LIMIT = 3;
    private const HTTP_CHUNK_SIZE = 1024 * 1024; // 1MB
    private const HTTP_USER_AGENT = 'RemoteDownloader/1.0';
    private const HTTP_TIMEOUT = 15.0;
    private const HTTP_CONNECT_TIMEOUT = 5.0;
    private const HTTP_READ_TIMEOUT = 10.0;
    private const HTTP_ALLOWED_PORTS = [80, 443];

    /** NOTA: la ruta temporal debe residir en un directorio seguro y no ser reubicable por usuarios no confiables. */

    /**
     * Constructor de la clase.
     *
     * @param FilesystemAdapter $disk Instancia del adaptador de sistema de archivos remoto.
     */
    public function __construct(
        private readonly FilesystemAdapter $disk,
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
        if ($expectedBytes <= 0) {
            throw new RuntimeException('expected_bytes_invalid');
        }
        if ($relativePath === '') {
            throw new RuntimeException('relative_path_invalid');
        }

        if ($this->isHttpUrl($relativePath)) {
            return $this->downloadFromHttp($relativePath, $tempPath, $expectedBytes);
        }

        if (str_starts_with($relativePath, '/') || str_contains($relativePath, '..')) {
            throw new RuntimeException('relative_path_invalid');
        }

        $stream = $this->disk->readStream($relativePath);
        if ($stream === false || !\is_resource($stream)) {
            throw new RuntimeException('stream_read_failed');
        }

        $out = fopen($tempPath, 'wb');
        if ($out === false || !\is_resource($out)) {
            fclose($stream);
            throw new RuntimeException('tmp_open_failed');
        }

        if (\function_exists('stream_set_timeout')) {
            stream_set_timeout($stream, 30);
        }

        $copied = 0;
        $chunkSize = 1024 * 1024; // 1MB chunks

        try {
            while (!feof($stream)) {
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
            @\unlink($tempPath);
            throw $exception;
        } finally {
            fclose($out);
            fclose($stream);
        }

        if ($copied === 0) {
            @\unlink($tempPath);
            throw new RuntimeException('stream_copy_empty');
        }

        if ($copied < $expectedBytes) {
            @\unlink($tempPath);
            throw new RuntimeException('stream_copy_incomplete');
        }

        return (int) $copied;
    }

    /**
     * Determina si una cadena representa una URL HTTP/HTTPS.
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
     */
    private function downloadFromHttp(string $url, string $tempPath, int $expectedBytes): int
    {
        $target = $this->resolveHttpTarget($url);
        [$finalTarget, $head] = $this->followHeadRedirects($target);

        $mime = $this->extractMime($head);
        if ($mime === null || !\in_array($mime, self::HTTP_ALLOWED_MIMES, true)) {
            throw new RuntimeException('http_head_mime_blocked');
        }

        $contentLength = $this->extractContentLength($head);
        if ($contentLength <= 0) {
            throw new RuntimeException('http_head_length_invalid');
        }
        if ($contentLength > self::HTTP_MAX_BYTES) {
            throw new RuntimeException('http_head_length_exceeds_limit');
        }

        if ($expectedBytes !== $contentLength) {
            throw new RuntimeException('expected_bytes_mismatch');
        }

        $out = fopen($tempPath, 'wb');
        if ($out === false || !\is_resource($out)) {
            throw new RuntimeException('tmp_open_failed');
        }

        $binding = $finalTarget['binding'];
        $response = $this->httpClient(true, $binding)->get($finalTarget['url']);
        if ($response->failed()) {
            fclose($out);
            throw new RuntimeException('http_get_failed');
        }

        $body = $response->toPsrResponse()->getBody();
        $copied = 0;

        try {
            while (!$body->eof()) {
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
            @\unlink($tempPath);
            throw $exception;
        }

        fclose($out);
        $body->close();

        if ($copied !== $contentLength) {
            @\unlink($tempPath);
            throw new RuntimeException('http_stream_length_mismatch');
        }

        return $copied;
    }

    /**
     * Normaliza y valida la URL de entrada.
     */
    private function resolveHttpTarget(string $url): array
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

        $ips = $this->assertHostAllowed($host);

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
     */
    private function followHeadRedirects(array $target): array
    {
        $current = $target;
        $redirects = 0;

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

            if (++$redirects > self::HTTP_REDIRECT_LIMIT) {
                throw new RuntimeException('http_redirect_limit_exceeded');
            }

            $nextUrl = $this->resolveRedirectUrl($current['url'], $location);
            $current = $this->resolveHttpTarget($nextUrl);
        }
    }

    /**
     * @param  array{url:string,scheme:string,host:string,port:int,ips:array<int,string>}  $target
     * @return array{0:Response,1:array{host:string,port:int,ip:string}}
     */
    private function performHeadRequest(array $target): array
    {
        foreach ($target['ips'] as $ip) {
            $binding = [
                'host' => $target['host'],
                'port' => $target['port'],
                'ip'   => $ip,
            ];

            try {
                $response = $this->httpClient(false, $binding)->head($target['url']);
                return [$response, $binding];
            } catch (ConnectionException) {
                continue;
            }
        }

        throw new RuntimeException('http_head_unreachable');
    }

    /**
     * Extrae y normaliza el MIME desde la respuesta HEAD.
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

        return (int) $header;
    }

    /**
     * Verifica que el host no resuelva a IPs internas, loopback o link-local.
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
     * @return array<int, string>
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
                throw new RuntimeException('dns_ip_blocked');
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

        if ($ip === '169.254.169.254') {
            throw new RuntimeException('dns_ip_blocked');
        }
    }

    /**
     * Determina si una IP es link-local (IPv4 169.254/16 o IPv6 fe80::/10).
     */
    private function isLinkLocal(string $ip): bool
    {
        if (str_contains($ip, ':')) {
            // IPv6 link-local: fe80::/10
            return str_starts_with(strtolower($ip), 'fe8')
                || str_starts_with(strtolower($ip), 'fe9')
                || str_starts_with(strtolower($ip), 'fea')
                || str_starts_with(strtolower($ip), 'feb');
        }

        // IPv4 link-local: 169.254.0.0/16
        return str_starts_with($ip, '169.254.');
    }

    /**
     * Construye las opciones base para peticiones HTTP.
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
     * Crea instancia de cliente HTTP con headers y opciones por defecto.
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
            $request = $request->withHeaders(['Host' => $binding['host']]);
        }

        return $request;
    }

    /**
     * Resuelve la URL absoluta de un Location de redirección.
     */
    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        $base = new Uri($baseUrl);
        $target = new Uri($location);
        return (string) UriResolver::resolve($base, $target);
    }

    private function isIpLiteral(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    private function formatCurlResolveEntry(string $host, int $port, string $ip): string
    {
        $ipFormatted = str_contains($ip, ':') ? "[{$ip}]" : $ip;

        return "{$host}:{$port}:{$ipFormatted}";
    }
}
