<?php

declare(strict_types=1);

namespace App\Support\Security\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Se lanza cuando un escáner antivirus no puede ejecutarse de forma segura.
 */
final class AntivirusException extends RuntimeException
{
    public const REASON_TIMEOUT              = 'timeout';
    public const REASON_PROCESS_TIMEOUT      = 'process_timeout';
    public const REASON_UNREACHABLE          = 'unreachable';
    public const REASON_CONNECTION_REFUSED   = 'connection_refused';
    public const REASON_RULESET_INVALID      = 'ruleset_invalid';
    public const REASON_RULESET_MISSING      = 'ruleset_missing';
    public const REASON_RULES_INTEGRITY_FAILED = 'rules_integrity_failed';
    public const REASON_RULES_PATH_INVALID   = 'rules_path_invalid';
    public const REASON_RULES_MISSING        = 'rules_missing';
    public const REASON_BINARY_MISSING       = 'binary_missing';
    public const REASON_BUILD_FAILED         = 'build_failed';
    public const REASON_ALLOWLIST_EMPTY      = 'allowlist_empty';
    public const REASON_TARGET_HANDLE_INVALID = 'target_handle_invalid';
    public const REASON_TARGET_HANDLE_UNSEEKABLE = 'target_handle_unseekable';
    public const REASON_TARGET_MISSING_DISPLAY_NAME = 'target_missing_display_name';
    public const REASON_TARGET_MISSING       = 'target_missing';
    public const REASON_PROCESS_EXCEPTION    = 'process_exception';
    public const REASON_PROCESS_FAILED       = 'process_failed';
    public const REASON_FILE_TOO_LARGE       = 'file_too_large';
    public const REASON_FILE_SIZE_UNKNOWN    = 'file_size_unknown';

    public function __construct(
        private readonly string $scanner,
        private readonly string $reason,
        ?Throwable $previous = null,
    ) {
        $message = sprintf('Antivirus scanner "%s" failed: %s', $scanner, $reason);
        parent::__construct($message, previous: $previous);
    }

    public function scanner(): string
    {
        return $this->scanner;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
