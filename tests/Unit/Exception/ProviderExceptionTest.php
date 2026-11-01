<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Exception;

use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Exception\UntrustedText;
use HtmlTranslator\Provider\TokenUsage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ProviderException::class)]
#[UsesClass(TokenUsage::class)]
#[UsesClass(UntrustedText::class)]
final class ProviderExceptionTest extends TestCase
{
    #[Test]
    public function it_carries_http_details(): void
    {
        $previous = new RuntimeException('Connection reset');

        $exception = ProviderException::fromHttpResponse(429, 'Rate limit exceeded after 3 attempts.', 'rate_limit_exceeded', $previous);

        self::assertSame('Rate limit exceeded after 3 attempts.', $exception->getMessage());
        self::assertSame(429, $exception->statusCode);
        self::assertSame(429, $exception->getCode());
        self::assertSame('rate_limit_exceeded', $exception->vendorCode);
        self::assertSame($previous, $exception->getPrevious());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function absentVendorCodes(): iterable
    {
        yield 'none' => [null];
        yield 'empty' => [''];
        yield 'whitespace' => [' '];
    }

    #[Test]
    #[DataProvider('absentVendorCodes')]
    public function it_needs_no_vendor_code(?string $vendorCode): void
    {
        $exception = ProviderException::fromHttpResponse(503, 'Service unavailable.', $vendorCode);

        self::assertSame(503, $exception->statusCode);
        self::assertNull($exception->vendorCode);
        self::assertNull($exception->getPrevious());
    }

    /**
     * Adapters wrap transport errors with the inherited constructor; that must keep working (ADR-0010).
     */
    #[Test]
    public function it_keeps_the_inherited_constructor(): void
    {
        $previous = new RuntimeException('Connection reset');

        $exception = new ProviderException('Cannot reach the service.', 0, $previous);

        self::assertSame('Cannot reach the service.', $exception->getMessage());
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame(0, $exception->getCode());
        self::assertNull($exception->statusCode);
        self::assertNull($exception->vendorCode);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidStatusCodes(): iterable
    {
        yield 'zero (e.g. a curl errno mistaken for a status)' => [0];
        yield 'below 100' => [99];
        yield 'above 599' => [600];
    }

    /**
     * A non-compliant PSR-7 response may report any status; the failure is still the provider's (#77).
     */
    #[Test]
    #[DataProvider('invalidStatusCodes')]
    public function it_keeps_no_status_code_for_an_impossible_one(int $statusCode): void
    {
        $exception = ProviderException::fromHttpResponse($statusCode, 'The server returned HTTP 999.', 'overloaded');

        self::assertSame('The server returned HTTP 999.', $exception->getMessage());
        self::assertNull($exception->statusCode);
        self::assertSame(0, $exception->getCode());
        self::assertSame('overloaded', $exception->vendorCode);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableVendorCodes(): iterable
    {
        yield 'line break' => ["rate_limit\nFAKE LOG LINE"];
        yield 'carriage return' => ["rate\rlimit"];
        yield '10 kB of text' => [str_repeat('x', 10_240)];
        yield '101 characters' => [str_repeat('a', 101)];
        yield 'NUL' => ["rate\0limit"];
        yield 'ANSI escape' => ["\x1b[31mrate_limit"];
        yield 'bidi override' => ["\u{202E}timil_etar"];
        yield 'invalid UTF-8' => ["rate_limit\xFF"];
    }

    /**
     * The vendor code is something to match on, so one that is not a plain identifier is dropped, not cut (#77).
     */
    #[Test]
    #[DataProvider('unusableVendorCodes')]
    public function it_drops_a_vendor_code_that_is_not_one_bounded_line(string $vendorCode): void
    {
        self::assertNull(ProviderException::fromHttpResponse(400, 'Bad request.', $vendorCode)->vendorCode);
    }

    #[Test]
    public function it_keeps_a_vendor_code_of_up_to_100_characters_and_trims_it(): void
    {
        self::assertSame(str_repeat('ż', 100), ProviderException::fromHttpResponse(400, 'x', str_repeat('ż', 100))->vendorCode);
        self::assertSame('invalid_request_error', ProviderException::fromHttpResponse(400, 'x', " invalid_request_error\t")->vendorCode);
    }

    #[Test]
    public function it_carries_the_tokens_the_failing_call_reported(): void
    {
        $usage = new TokenUsage(1200, 16000);

        self::assertSame($usage, new ProviderException('Cut off.', usage: $usage)->usage);
        self::assertSame($usage, ProviderException::fromHttpResponse(500, 'x', usage: $usage)->usage);
        self::assertNull(new ProviderException('Cannot reach the service.')->usage);
    }

    #[Test]
    public function it_copies_itself_with_other_usage(): void
    {
        $previous = new RuntimeException('Connection reset');
        $exception = ProviderException::fromHttpResponse(429, 'Rate limit exceeded.', 'rate_limit_exceeded', $previous);

        $copy = $exception->withUsage(new TokenUsage(10, null));

        self::assertNotSame($exception, $copy);
        self::assertNull($exception->usage);
        self::assertEquals(new TokenUsage(10, null), $copy->usage);
        self::assertSame('Rate limit exceeded.', $copy->getMessage());
        self::assertSame(429, $copy->getCode());
        self::assertSame(429, $copy->statusCode);
        self::assertSame('rate_limit_exceeded', $copy->vendorCode);
        self::assertSame($previous, $copy->getPrevious());
        self::assertNull($copy->withUsage(null)->usage);
    }

    #[Test]
    public function it_accepts_the_status_code_range_bounds(): void
    {
        self::assertSame(100, ProviderException::fromHttpResponse(100, 'x')->statusCode);
        self::assertSame(599, ProviderException::fromHttpResponse(599, 'x')->statusCode);
    }
}
