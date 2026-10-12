<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Provider\Llm\Bridge;

use HtmlTranslator\Provider\Llm\Bridge\RetriedResponse;
use HtmlTranslator\Provider\Llm\Bridge\Retrier;
use HtmlTranslator\Provider\Llm\Bridge\RetryPolicy;
use HtmlTranslator\Provider\Llm\Bridge\TransportFailure;
use HtmlTranslator\Tests\Support\FakeClientException;
use HtmlTranslator\Tests\Support\FakeHttpClient;
use HtmlTranslator\Tests\Support\FakeNetworkException;
use HtmlTranslator\Tests\Support\FakeSleeper;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Throwable;

#[CoversClass(Retrier::class)]
#[CoversClass(RetriedResponse::class)]
#[CoversClass(TransportFailure::class)]
#[UsesClass(RetryPolicy::class)]
final class RetrierTest extends TestCase
{
    private const string API_KEY = 'sk-test-SECRET-0123456789';

    private FakeSleeper $sleeper;

    /** @var list<string> bodies of the requests, as the server received them */
    private array $bodies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sleeper = new FakeSleeper();
    }

    #[Test]
    public function it_waits_as_long_as_retry_after_says_and_then_succeeds(): void
    {
        $result = $this->retrier()->send($this->client([new Response(429, ['Retry-After' => '2']), new Response(200, [], 'ok')]), $this->request());

        self::assertSame(200, $result->response->getStatusCode());
        self::assertSame(2, $result->attempts);
        self::assertSame([2000], $this->sleeper->delays);
    }

    #[Test]
    public function it_reads_a_retry_after_date_against_the_response_date(): void
    {
        $limited = new Response(503, ['Date' => 'Mon, 12 Oct 2026 10:00:00 GMT', 'Retry-After' => 'Mon, 12 Oct 2026 10:00:05 GMT']);

        $this->retrier()->send($this->client([$limited, new Response(200)]), $this->request());

        self::assertSame([5000], $this->sleeper->delays);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function unusableRetryAfter(): iterable
    {
        yield 'date without a response date' => [['Retry-After' => 'Mon, 12 Oct 2026 10:00:05 GMT']];
        yield 'garbage' => [['Retry-After' => 'soon']];
        yield 'negative' => [['Retry-After' => '-3']];
        yield 'date equal to the response date' => [['Date' => 'Mon, 12 Oct 2026 10:00:05 GMT', 'Retry-After' => 'Mon, 12 Oct 2026 10:00:05 GMT']];
        yield 'date in the past' => [['Date' => 'Mon, 12 Oct 2026 10:00:05 GMT', 'Retry-After' => 'Mon, 12 Oct 2026 10:00:00 GMT']];
    }

    /**
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('unusableRetryAfter')]
    public function it_falls_back_to_the_backoff_for_an_unusable_retry_after(array $headers): void
    {
        $this->retrier()->send($this->client([new Response(503, $headers), new Response(200)]), $this->request());

        self::assertCount(1, $this->sleeper->delays);
        self::assertGreaterThanOrEqual(500, $this->sleeper->delays[0]);
        self::assertLessThanOrEqual(1000, $this->sleeper->delays[0]);
    }

    #[Test]
    public function it_caps_retry_after_at_the_maximum_delay(): void
    {
        $this->retrier(new RetryPolicy(maxDelayMilliseconds: 5000))->send($this->client([new Response(429, ['Retry-After' => '3600']), new Response(200)]), $this->request());

        self::assertSame([5000], $this->sleeper->delays);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function permanentStatuses(): iterable
    {
        yield 'bad request' => [400];
        yield 'unauthorized' => [401];
        yield 'not found' => [404];
    }

    #[Test]
    #[DataProvider('permanentStatuses')]
    public function it_does_not_retry_permanent_errors(int $status): void
    {
        $result = $this->retrier()->send($this->client([new Response($status), new Response(200)]), $this->request());

        self::assertSame($status, $result->response->getStatusCode());
        self::assertSame(1, $result->attempts);
        self::assertSame([], $this->sleeper->delays);
    }

    #[Test]
    public function it_stops_after_the_last_attempt_with_growing_waits(): void
    {
        $result = $this->retrier(new RetryPolicy(baseDelayMilliseconds: 1000))->send($this->client([new Response(500), new Response(500), new Response(500), new Response(200)]), $this->request());

        self::assertSame(500, $result->response->getStatusCode());
        self::assertSame(3, $result->attempts);
        self::assertCount(2, $this->sleeper->delays);
        self::assertLessThanOrEqual(1000, $this->sleeper->delays[0]);
        self::assertGreaterThanOrEqual(1000, $this->sleeper->delays[1]);
        self::assertLessThanOrEqual(2000, $this->sleeper->delays[1]);
    }

    #[Test]
    public function it_retries_network_errors(): void
    {
        $result = $this->retrier()->send($this->client(['network', 'network', new Response(200)]), $this->request());

        self::assertSame(200, $result->response->getStatusCode());
        self::assertSame(3, $result->attempts);
        self::assertCount(2, $this->sleeper->delays, 'It backs off between network errors too.');
    }

    #[Test]
    public function it_reports_a_network_error_that_lasts_with_the_number_of_attempts(): void
    {
        try {
            $this->retrier()->send($this->client(['network', 'network', 'network']), $this->request());
            self::fail('Expected a TransportFailure.');
        } catch (TransportFailure $failure) {
            self::assertSame(3, $failure->attempts);
            self::assertInstanceOf(FakeNetworkException::class, $failure->getPrevious());
        }
    }

    #[Test]
    public function it_does_not_retry_other_client_errors(): void
    {
        try {
            $this->retrier()->send($this->client(['client', new Response(200)]), $this->request());
            self::fail('Expected a TransportFailure.');
        } catch (TransportFailure $failure) {
            self::assertSame(1, $failure->attempts);
            self::assertInstanceOf(FakeClientException::class, $failure->getPrevious());
            self::assertSame([], $this->sleeper->delays);
        }
    }

    #[Test]
    public function it_does_not_retry_network_errors_when_told_not_to(): void
    {
        $this->expectException(TransportFailure::class);

        $this->retrier(new RetryPolicy(retryNetworkErrors: false))->send($this->client(['network', new Response(200)]), $this->request());
    }

    #[Test]
    public function it_makes_one_attempt_when_retries_are_off(): void
    {
        $result = $this->retrier(RetryPolicy::none())->send($this->client([new Response(503), new Response(200)]), $this->request());

        self::assertSame(503, $result->response->getStatusCode());
        self::assertSame(1, $result->attempts);
    }

    #[Test]
    public function it_sends_the_whole_body_on_every_attempt(): void
    {
        $this->retrier()->send($this->client([new Response(503), new Response(200)]), $this->request());

        self::assertSame(['{"model":"m"}', '{"model":"m"}'], $this->bodies);
    }

    #[Test]
    public function it_keeps_the_request_out_of_the_recorded_trace(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');

        try {
            $this->retrier()->send($this->client(['network', 'network', 'network']), $this->request());
            self::fail('Expected a TransportFailure.');
        } catch (TransportFailure $failure) {
            // Only the library's frames matter: the runner's frames hold this test case, and the fake client's
            // closure is bound to it, so dumping them would show the requests this test keeps itself.
            $frames = array_filter($failure->getTrace(), static fn(array $frame): bool => str_starts_with($frame['class'] ?? '', 'HtmlTranslator\\Provider\\'));
            self::assertNotSame([], $frames);
            foreach ($frames as $frame) {
                foreach ($frame['args'] ?? [] as $argument) {
                    self::assertNotInstanceOf(RequestInterface::class, $argument, 'The request with its key header must not be recorded.');
                    self::assertNotInstanceOf(Throwable::class, $argument);
                    self::assertFalse(\is_string($argument) && str_contains($argument, self::API_KEY));
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', false === $previous ? '1' : $previous);
        }
    }

    private function retrier(?RetryPolicy $policy = null): Retrier
    {
        return new Retrier($policy ?? RetryPolicy::default(), $this->sleeper, new Randomizer(new Mt19937(3)));
    }

    /**
     * @param list<ResponseInterface|'network'|'client'> $script one answer per attempt
     */
    private function client(array $script): FakeHttpClient
    {
        return new FakeHttpClient(function (RequestInterface $request) use (&$script): ResponseInterface {
            // getContents() reads from the current position, as a real client does: an unrewound body is empty.
            $this->bodies[] = $request->getBody()->getContents();
            $next = array_shift($script) ?? self::fail('No answer left for this attempt.');

            return match ($next) {
                'network' => throw new FakeNetworkException('Connection reset', $request),
                'client' => throw new FakeClientException('Invalid request'),
                default => $next,
            };
        });
    }

    private function request(): RequestInterface
    {
        $factory = new Psr17Factory();

        return $factory->createRequest('POST', 'https://api.example.test/v1/messages')
            ->withHeader('x-api-key', self::API_KEY)
            ->withBody($factory->createStream('{"model":"m"}'));
    }
}
