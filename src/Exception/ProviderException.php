<?php

declare(strict_types=1);

namespace HtmlTranslator\Exception;

use HtmlTranslator\Provider\TokenUsage;
use RuntimeException;
use Throwable;

/**
 * A provider could not translate a batch at all, even after its own transport retries (e.g. the service is down).
 * Failures of single units are reported in BatchResult instead.
 *
 * The constructor ($message, $code, $previous, $usage) wraps transport and decoding errors; fromHttpResponse() adds
 * the HTTP status and the vendor's error code. Messages must never contain API keys, tokens or other credentials.
 *
 * A failed call can still be billed (an answer cut off at the output limit, a body the adapter cannot read), so the
 * exception carries the tokens the failing response reported; HtmlTranslator adds them to its stats.
 */
final class ProviderException extends RuntimeException implements TranslatorException
{
    /** Longest vendor code kept; codes are identifiers such as "rate_limit_exceeded". */
    private const int MAX_VENDOR_CODE_LENGTH = 100;

    /** HTTP status of the failing response, when there was one with a valid status (100–599). */
    public private(set) ?int $statusCode = null;

    /**
     * The vendor's own error code (e.g. "rate_limit_exceeded"), when it sent one that is a single line of at most 100
     * characters without control or bidi characters; anything else is dropped, not cut, because a cut code would
     * match the wrong thing.
     */
    public private(set) ?string $vendorCode = null;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        /** Tokens the failing call reported, when its response reported any. */
        public private(set) ?TokenUsage $usage = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * getCode() returns the status as well, for tools that only know the standard exception API. A status outside
     * 100–599 (from a non-compliant PSR-7 implementation) is not kept: the exception has no status code then.
     */
    public static function fromHttpResponse(int $statusCode, string $message, ?string $vendorCode = null, ?Throwable $previous = null, ?TokenUsage $usage = null): self
    {
        $status = $statusCode >= 100 && $statusCode <= 599 ? $statusCode : null;

        $exception = new self($message, $status ?? 0, $previous, $usage);
        $exception->statusCode = $status;
        $exception->vendorCode = self::vendorCode($vendorCode);

        return $exception;
    }

    /**
     * The same failure with other token usage, e.g. with the tokens of earlier calls added by a provider or the
     * translator that rethrows it. Exceptions cannot be cloned, so this is a new one with the same message, code,
     * status, vendor code and previous exception; only its trace starts where withUsage() is called.
     */
    public function withUsage(?TokenUsage $usage): self
    {
        $copy = new self($this->getMessage(), $this->getCode(), $this->getPrevious(), $usage);
        $copy->statusCode = $this->statusCode;
        $copy->vendorCode = $this->vendorCode;

        return $copy;
    }

    private static function vendorCode(?string $code): ?string
    {
        $code = null === $code ? '' : trim($code);

        // Quoting changes nothing exactly when the code is valid UTF-8, short enough and free of unsafe characters.
        return '' !== $code && UntrustedText::quote($code, self::MAX_VENDOR_CODE_LENGTH) === $code ? $code : null;
    }
}
