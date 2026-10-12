<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm\Bridge;

use DateTimeImmutable;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Random\Randomizer;
use SensitiveParameter;

/**
 * Sends a request through a PSR-18 client and retries transient failures by a RetryPolicy (ARCHITECTURE §6.8), for
 * the chat model adapters. Waits honour Retry-After (seconds, or an HTTP date measured against the response's Date
 * header, so no clock is needed) and otherwise back off exponentially with jitter, always up to the maximum delay.
 */
final readonly class Retrier
{
    private RetryPolicy $policy;

    public function __construct(
        ?RetryPolicy $policy = null,
        private Sleeper $sleeper = new UsleepSleeper(),
        private Randomizer $randomizer = new Randomizer(),
    ) {
        $this->policy = $policy ?? RetryPolicy::default();
    }

    /**
     * @param RequestInterface $request sensitive: its headers carry the API key
     *
     * @throws TransportFailure when the client fails on the last attempt, or with an error that is not worth retrying
     */
    public function send(ClientInterface $client, #[SensitiveParameter] RequestInterface $request): RetriedResponse
    {
        for ($attempt = 1; ; ++$attempt) {
            // The request is immutable, but its body stream is shared between attempts.
            $body = $request->getBody();
            if ($body->isSeekable()) {
                $body->rewind();
            }

            try {
                $response = $client->sendRequest($request);
            } catch (ClientExceptionInterface $exception) {
                if (!$exception instanceof NetworkExceptionInterface || !$this->policy->retryNetworkErrors || $attempt >= $this->policy->maxAttempts) {
                    throw new TransportFailure($attempt, $exception);
                }

                $this->sleeper->sleep($this->policy->backoffMilliseconds($attempt, $this->randomizer));

                continue;
            }

            if ($attempt >= $this->policy->maxAttempts || !$this->policy->retries($response->getStatusCode())) {
                return new RetriedResponse($response, $attempt);
            }

            $this->sleeper->sleep(min(
                $this->retryAfterMilliseconds($response) ?? $this->policy->backoffMilliseconds($attempt, $this->randomizer),
                $this->policy->maxDelayMilliseconds,
            ));
        }
    }

    private function retryAfterMilliseconds(ResponseInterface $response): ?int
    {
        $value = trim($response->getHeaderLine('Retry-After'));
        if ('' === $value) {
            return null;
        }

        if (ctype_digit($value)) {
            return min((int) $value, intdiv(\PHP_INT_MAX, 1000)) * 1000;
        }

        $retryAt = DateTimeImmutable::createFromFormat(\DATE_RFC7231, $value);
        $sentAt = DateTimeImmutable::createFromFormat(\DATE_RFC7231, trim($response->getHeaderLine('Date')));
        if (false === $retryAt || false === $sentAt || $retryAt <= $sentAt) {
            return null;
        }

        return ($retryAt->getTimestamp() - $sentAt->getTimestamp()) * 1000;
    }
}
