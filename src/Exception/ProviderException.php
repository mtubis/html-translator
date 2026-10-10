<?php

declare(strict_types=1);

namespace HtmlTranslator\Exception;

use RuntimeException;
use Throwable;

/**
 * A provider could not translate a batch at all, even after its own transport retries (e.g. the service is down).
 * Failures of single units are reported in BatchResult instead.
 *
 * The inherited constructor ($message, $code, $previous) wraps transport and decoding errors; fromHttpResponse()
 * adds the HTTP status and the vendor's error code. Messages must never contain API keys, tokens or other credentials.
 */
final class ProviderException extends RuntimeException implements TranslatorException
{
    /** HTTP status of the failing response, when there was one. */
    public private(set) ?int $statusCode = null;

    /** The vendor's own error code (e.g. "rate_limit_exceeded"), when it sent one. */
    public private(set) ?string $vendorCode = null;

    /**
     * getCode() returns the status as well, for tools that only know the standard exception API.
     *
     * @throws InvalidArgumentException when the status is not an HTTP status code (100–599)
     */
    public static function fromHttpResponse(int $statusCode, string $message, ?string $vendorCode = null, ?Throwable $previous = null): self
    {
        if ($statusCode < 100 || $statusCode > 599) {
            throw new InvalidArgumentException(\sprintf('%d is not an HTTP status code.', $statusCode));
        }

        $exception = new self($message, $statusCode, $previous);
        $exception->statusCode = $statusCode;
        $exception->vendorCode = null === $vendorCode || '' === trim($vendorCode) ? null : $vendorCode;

        return $exception;
    }
}
