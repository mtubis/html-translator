<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Exception;

use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\ProviderException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ProviderException::class)]
#[UsesClass(InvalidArgumentException::class)]
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

    #[Test]
    #[DataProvider('invalidStatusCodes')]
    public function it_rejects_impossible_status_codes(int $statusCode): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProviderException::fromHttpResponse($statusCode, 'x');
    }

    #[Test]
    public function it_accepts_the_status_code_range_bounds(): void
    {
        self::assertSame(100, ProviderException::fromHttpResponse(100, 'x')->statusCode);
        self::assertSame(599, ProviderException::fromHttpResponse(599, 'x')->statusCode);
    }
}
