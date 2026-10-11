<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Support;

use Closure;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 test double: answers through a callback (which may throw a ClientExceptionInterface) and records requests.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> requests in the order they were sent */
    public private(set) array $requests = [];

    /**
     * @param Closure(RequestInterface): ResponseInterface $answer
     */
    public function __construct(
        private readonly Closure $answer,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return ($this->answer)($request);
    }
}
