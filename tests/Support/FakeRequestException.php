<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Support;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

/**
 * A request the client could not send (e.g. an invalid header), holding that request with its credential headers.
 */
final class FakeRequestException extends RuntimeException implements RequestExceptionInterface
{
    public function __construct(
        string $message,
        private readonly RequestInterface $request,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
