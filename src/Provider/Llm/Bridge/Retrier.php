<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm\Bridge;

use DateTimeImmutable;
use DateTimeZone;
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
    /** IMF-fixdate (RFC 9110), the format of Date and Retry-After; DATE_RFC7231 is deprecated as of PHP 8.5. */
    /** IMF-fixdate (RFC 9110), parsed from midnight so no field comes from the current time. */
    private const string HTTP_DATE = '!D, d M Y H:i:s \\G\\M\\T';

    private const string HTTP_DATE_OUT = 'D, d M Y H:i:s \\G\\M\\T';

    public function __construct(
        private RetryPolicy $policy = new RetryPolicy(),
        private SleeperInterface $sleeper = new UsleepSleeper(),
        private Randomizer $randomizer = new Randomizer(),
    ) {}

    /**
     * @param RequestInterface $request sensitive: its headers carry the API key
     *
     * @throws TransportFailure when the client fails on the last attempt, or with an error that is not worth retrying;
     *                          turn it into a ProviderException (see TransportFailure)
     */
    public function send(ClientInterface $client, #[SensitiveParameter] RequestInterface $request): RetriedResponse
    {
        // The request is immutable, but its body stream is shared between attempts: one that cannot be rewound would
        // be sent empty the second time.
        $body = $request->getBody();
        $maxAttempts = $body->isSeekable() ? $this->policy->maxAttempts : 1;

        for ($attempt = 1; ; ++$attempt) {
            if ($body->isSeekable()) {
                $body->rewind();
            }

            try {
                $response = $client->sendRequest($request);
            } catch (ClientExceptionInterface $exception) {
                if (!$exception instanceof NetworkExceptionInterface || !$this->policy->retryNetworkErrors || $attempt >= $maxAttempts) {
                    throw new TransportFailure($attempt, $exception);
                }

                $this->sleeper->sleep($this->policy->backoffMilliseconds($attempt, $this->randomizer));

                continue;
            }

            if ($attempt >= $maxAttempts || !$this->policy->retries($response->getStatusCode())) {
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
        // PSR-7 implementations trim header values.
        $value = $response->getHeaderLine('Retry-After');
        if (ctype_digit($value)) {
            return min((int) $value, intdiv(\PHP_INT_MAX, 1000)) * 1000;
        }

        $retryAt = $this->httpDate($value);
        $sentAt = $this->httpDate($response->getHeaderLine('Date'));
        if (!$retryAt instanceof DateTimeImmutable || !$sentAt instanceof DateTimeImmutable || $retryAt <= $sentAt) {
            return null;
        }

        return ($retryAt->getTimestamp() - $sentAt->getTimestamp()) * 1000;
    }

    /**
     * A strict IMF-fixdate in GMT. createFromFormat() quietly rolls over a day 32 and follows a wrong weekday to another
     * date, so the parsed date must format back to the same text.
     */
    private function httpDate(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(self::HTTP_DATE, $value, new DateTimeZone('UTC'));

        return false !== $date && $date->format(self::HTTP_DATE_OUT) === $value ? $date : null;
    }
}
