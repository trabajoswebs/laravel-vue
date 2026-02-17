<?php

declare(strict_types=1);

namespace App\Infrastructure\Uploads\Core\Orchestrators;

use App\Application\Uploads\Exceptions\InvalidUploadFileException;
use App\Domain\Uploads\UploadProfile;
use App\Modules\Uploads\Pipeline\Security\MimeNormalizer;
use Illuminate\Http\UploadedFile;

final class DocumentUploadGuard
{
    public function validate(UploadProfile $profile, UploadedFile $file): void
    {
        $realPath = $file->getRealPath();
        if (!is_string($realPath) || $realPath === '' || !is_file($realPath)) {
            throw new InvalidUploadFileException('No se pudo leer el archivo subido');
        }

        $size = $file->getSize();
        if ($size === null) {
            $size = filesize($realPath);
        }

        $size = (int) ($size ?? 0);
        if ($size <= 0 || $size > (int) $profile->maxBytes) {
            throw new InvalidUploadFileException('Tamaño de archivo no permitido para el perfil');
        }

        $mime = $this->resolveMime($profile, $file, $realPath);
        $allowedMimes = $this->normalizedAllowedMimes($profile);
        if ($mime === null || !in_array($mime, $allowedMimes, true)) {
            throw new InvalidUploadFileException('MIME no permitido para el perfil');
        }

        $magic = $this->readMagicPrefix($realPath, 8);
        $bytes = $magic !== '' ? bin2hex($magic) : '';

        $signatureOk = match ((string) $profile->pathCategory) {
            'documents' => str_starts_with($magic, '%PDF'),
            'spreadsheets' => str_starts_with($bytes, '504b0304'),
            'imports' => $this->isAcceptedImportPayload($realPath),
            'secrets' => $this->isAcceptedSecretPayload($mime, (string) $file->getClientOriginalExtension(), $bytes),
            default => false,
        };

        if (!$signatureOk) {
            throw new InvalidUploadFileException('Firma de archivo inválida para el perfil');
        }
    }

    public function extensionFor(UploadProfile $profile, UploadedFile $file): string
    {
        $clientExt = strtolower((string) $file->getClientOriginalExtension());

        return match ((string) $profile->pathCategory) {
            'documents' => 'pdf',
            'spreadsheets' => 'xlsx',
            'imports' => 'csv',
            'secrets' => 'p12',
            default => $clientExt !== '' ? $clientExt : 'bin',
        };
    }

    private function resolveMime(UploadProfile $profile, UploadedFile $file, string $realPath): ?string
    {
        $trusted = $this->detectTrustedMime($realPath);
        $fallback = MimeNormalizer::normalize($file->getMimeType());
        $allowedMimes = $this->normalizedAllowedMimes($profile);

        if ((string) $profile->pathCategory === 'imports') {
            if ($trusted !== null && $trusted !== 'application/octet-stream' && in_array($trusted, $allowedMimes, true)) {
                return $trusted;
            }

            if (
                $trusted !== null
                && str_starts_with($trusted, 'text/')
                && in_array('text/plain', $allowedMimes, true)
                && $this->looksLikeTextImportPayload($realPath)
            ) {
                return 'text/plain';
            }

            if ($fallback !== null && in_array($fallback, $allowedMimes, true)) {
                return $fallback;
            }

            if (
                $fallback !== null
                && str_starts_with($fallback, 'text/')
                && in_array('text/plain', $allowedMimes, true)
                && $this->looksLikeTextImportPayload($realPath)
            ) {
                return 'text/plain';
            }

            return in_array('text/plain', $allowedMimes, true) && $this->looksLikeTextImportPayload($realPath)
                ? 'text/plain'
                : null;
        }

        if ((string) $profile->pathCategory === 'secrets') {
            if ($trusted !== null && in_array($trusted, $allowedMimes, true)) {
                return $trusted;
            }

            if ($fallback !== null && in_array($fallback, $allowedMimes, true)) {
                return $fallback;
            }

            return $trusted ?? $fallback;
        }

        if ($trusted === null || ($trusted === 'application/octet-stream' && $fallback !== null && $fallback !== 'application/octet-stream')) {
            return $fallback;
        }

        return $trusted;
    }

    /**
     * @return list<string>
     */
    private function normalizedAllowedMimes(UploadProfile $profile): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn(string $value): ?string => MimeNormalizer::normalize($value),
            $profile->allowedMimes
        ))));
    }

    private function detectTrustedMime(string $realPath): ?string
    {
        $trusted = null;
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            try {
                $mime = finfo_file($finfo, $realPath);
                $trusted = MimeNormalizer::normalize(is_string($mime) ? $mime : null);
            } finally {
                finfo_close($finfo);
            }
        }

        return $trusted;
    }

    private function isAcceptedImportPayload(string $realPath): bool
    {
        $sampleBytes = max(4096, (int) config('uploads.import_csv.sample_bytes', 65536));
        $sampleRaw = @file_get_contents($realPath, false, null, 0, $sampleBytes);
        if (!is_string($sampleRaw)) {
            return false;
        }

        $sample = $this->decodeToUtf8($sampleRaw);
        if (!is_string($sample) || trim($sample) === '') {
            return false;
        }

        if ($this->containsBinaryControlBytes($sample)) {
            return false;
        }

        if ($this->startsWithPhpTag($sample)) {
            return false;
        }

        $maxLines = max(5, (int) config('uploads.import_csv.sniff_lines', 20));
        $maxColumns = max(1, (int) config('uploads.import_csv.max_columns', 50));
        $maxLineLength = max(128, (int) config('uploads.import_csv.max_line_length', 8192));
        $requiredConsistency = (float) config('uploads.import_csv.min_consistency_ratio', 0.6);
        $requiredConsistency = min(1.0, max(0.0, $requiredConsistency));

        $rows = $this->extractNonEmptyLines($sample, $maxLines, $maxLineLength);
        if ($rows === null || $rows === []) {
            return false;
        }

        $delimiter = $this->sniffDelimiter($rows);

        $fullRaw = @file_get_contents($realPath);
        if (!is_string($fullRaw)) {
            return false;
        }

        $full = $this->decodeToUtf8($fullRaw);
        if (!is_string($full) || trim($full) === '') {
            return false;
        }

        if ($this->containsBinaryControlBytes($full) || $this->startsWithPhpTag($full)) {
            return false;
        }

        $columnCounts = $this->collectColumnCounts($full, $delimiter, $maxLines, $maxColumns, $maxLineLength);
        if ($columnCounts === null || $columnCounts === []) {
            return false;
        }

        $frequency = array_count_values($columnCounts);
        arsort($frequency);
        $expectedColumns = (int) array_key_first($frequency);
        if ($expectedColumns < 1 || $expectedColumns > $maxColumns) {
            return false;
        }

        $consistentRows = (int) ($frequency[$expectedColumns] ?? 0);
        return ($consistentRows / count($columnCounts)) >= $requiredConsistency;
    }

    private function looksLikeTextImportPayload(string $realPath): bool
    {
        $sampleBytes = max(4096, (int) config('uploads.import_csv.sample_bytes', 65536));
        $sampleRaw = @file_get_contents($realPath, false, null, 0, $sampleBytes);
        if (!is_string($sampleRaw)) {
            return false;
        }

        $sample = $this->decodeToUtf8($sampleRaw);
        if (!is_string($sample) || trim($sample) === '') {
            return false;
        }

        if ($this->containsBinaryControlBytes($sample) || $this->startsWithPhpTag($sample)) {
            return false;
        }

        return true;
    }

    /**
     * @return list<string>|null
     */
    private function extractNonEmptyLines(string $content, int $maxLines, int $maxLineLength): ?array
    {
        $lines = preg_split('/\R/u', $content) ?: [];
        $rows = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if (strlen($trimmed) > $maxLineLength) {
                return null;
            }

            $rows[] = $trimmed;
            if (count($rows) >= $maxLines) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @return list<int>|null
     */
    private function collectColumnCounts(
        string $utf8Content,
        string $delimiter,
        int $maxRows,
        int $maxColumns,
        int $maxLineLength,
    ): ?array {
        $lines = preg_split('/\R/u', $utf8Content) ?: [];
        foreach ($lines as $line) {
            if (strlen($line) > $maxLineLength) {
                return null;
            }
        }

        $stream = fopen('php://temp', 'wb+');
        if ($stream === false) {
            return null;
        }

        try {
            fwrite($stream, $utf8Content);
            rewind($stream);

            $columnCounts = [];
            while (($row = fgetcsv($stream, 0, $delimiter)) !== false) {
                if (!is_array($row)) {
                    return null;
                }

                if ($row !== []) {
                    $row[0] = $this->stripUtf8BomCell((string) $row[0]);
                }

                if ($this->isBlankCsvRow($row)) {
                    continue;
                }

                $count = count($row);
                if ($count < 1 || $count > $maxColumns) {
                    return null;
                }

                $columnCounts[] = $count;
                if (count($columnCounts) >= $maxRows) {
                    break;
                }
            }

            return $columnCounts;
        } finally {
            fclose($stream);
        }
    }

    private function decodeToUtf8(string $content): ?string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return substr($content, 3);
        }

        if (str_starts_with($content, "\xFF\xFE")) {
            $converted = $this->convertToUtf8(substr($content, 2), 'UTF-16LE');
            return is_string($converted) && $converted !== '' ? $converted : null;
        }

        if (str_starts_with($content, "\xFE\xFF")) {
            $converted = $this->convertToUtf8(substr($content, 2), 'UTF-16BE');
            return is_string($converted) && $converted !== '' ? $converted : null;
        }

        return $content;
    }

    private function convertToUtf8(string $content, string $fromEncoding): ?string
    {
        if (function_exists('iconv')) {
            $converted = @iconv($fromEncoding, 'UTF-8//IGNORE', $content);
            if (is_string($converted)) {
                return $converted;
            }
        }

        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($content, 'UTF-8', $fromEncoding);
            if (is_string($converted)) {
                return $converted;
            }
        }

        return null;
    }

    private function containsBinaryControlBytes(string $content): bool
    {
        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $content) === 1;
    }

    private function startsWithPhpTag(string $content): bool
    {
        return preg_match('/^<\?(?:php|=)?/i', ltrim($content)) === 1;
    }

    private function stripUtf8BomCell(string $value): string
    {
        if (str_starts_with($value, "\xEF\xBB\xBF")) {
            return substr($value, 3);
        }

        return $value;
    }

    /**
     * @param list<string> $row
     */
    private function isBlankCsvRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function sniffDelimiter(array $rows): string
    {
        $candidates = [',', ';', "\t", '|'];
        $bestDelimiter = ',';
        $bestScore = -1.0;

        foreach ($candidates as $candidate) {
            $counts = [];
            foreach ($rows as $row) {
                $parsed = str_getcsv($row, $candidate);
                $count = is_array($parsed) ? count($parsed) : 0;
                if ($count > 0) {
                    $counts[] = $count;
                }
            }

            if ($counts === []) {
                continue;
            }

            $frequency = array_count_values($counts);
            arsort($frequency);
            $modeColumns = (int) array_key_first($frequency);
            $modeCount = (int) ($frequency[$modeColumns] ?? 0);
            $score = $modeCount / count($counts);

            if ($score > $bestScore || ($score === $bestScore && $modeColumns > 1)) {
                $bestScore = $score;
                $bestDelimiter = $candidate;
            }
        }

        return $bestDelimiter;
    }

    private function readMagicPrefix(string $path, int $bytes): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        try {
            $data = fread($handle, $bytes);
        } finally {
            fclose($handle);
        }

        return is_string($data) ? $data : '';
    }

    private function isAcceptedSecretPayload(?string $mime, string $clientExtension, string $magicHex): bool
    {
        $normalizedExtension = strtolower(trim($clientExtension));
        if ($normalizedExtension === '' || !in_array($normalizedExtension, ['p12', 'pfx'], true)) {
            return false;
        }

        if ($mime !== null && !in_array($mime, ['application/x-pkcs12', 'application/octet-stream'], true)) {
            return false;
        }

        return str_starts_with($magicHex, '3082');
    }
}
