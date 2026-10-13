<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Provider;

use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\UntrustedText;
use HtmlTranslator\Provider\BatchResult;
use HtmlTranslator\Provider\TokenUsage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BatchResult::class)]
#[UsesClass(InvalidArgumentException::class)]
#[UsesClass(UntrustedText::class)]
#[UsesClass(TokenUsage::class)]
final class BatchResultTest extends TestCase
{
    #[Test]
    public function it_holds_translations_and_failures(): void
    {
        $result = new BatchResult(['u1' => 'Jeden'], ['u2' => 'refused']);

        self::assertSame(['u1' => 'Jeden'], $result->translations);
        self::assertSame(['u2' => 'refused'], $result->failures);
    }

    #[Test]
    public function it_has_no_failures_by_default(): void
    {
        self::assertSame([], new BatchResult(['u1' => 'Jeden'])->failures);
    }

    #[Test]
    public function it_rejects_a_failure_without_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"u2"');

        new BatchResult([], ['u2' => ' ']);
    }

    #[Test]
    public function it_holds_permanent_failures(): void
    {
        $result = new BatchResult(['u1' => 'Jeden'], ['u2' => 'timeout'], ['u3' => 'content policy']);

        self::assertSame(['u3' => 'content policy'], $result->permanentFailures);
    }

    #[Test]
    public function it_has_no_permanent_failures_by_default(): void
    {
        self::assertSame([], new BatchResult(['u1' => 'Jeden'], ['u2' => 'timeout'])->permanentFailures);
    }

    #[Test]
    public function it_has_no_token_usage_by_default(): void
    {
        self::assertNull(new BatchResult(['u1' => 'Jeden'])->usage);
    }

    #[Test]
    public function it_returns_a_copy_with_token_usage(): void
    {
        $result = new BatchResult(['u1' => 'Jeden'], ['u2' => 'timeout'], ['u3' => 'content policy']);

        $withUsage = $result->withUsage(new TokenUsage(10, 2));

        self::assertEquals(new BatchResult(['u1' => 'Jeden'], ['u2' => 'timeout'], ['u3' => 'content policy'], new TokenUsage(10, 2)), $withUsage);
        self::assertNull($result->usage);
    }

    /**
     * @return iterable<string, array{array<string, string>, array<string, string>, array<string, string>}>
     */
    public static function overlappingMaps(): iterable
    {
        yield 'translated and failed' => [['u1' => 'Jeden'], ['u1' => 'timeout'], []];
        yield 'translated and permanently failed' => [['u1' => 'Jeden'], [], ['u1' => 'content policy']];
        yield 'failed and permanently failed' => [[], ['u1' => 'timeout'], ['u1' => 'content policy']];
    }

    /**
     * @param array<string, string> $translations
     * @param array<string, string> $failures
     * @param array<string, string> $permanentFailures
     */
    #[Test]
    #[DataProvider('overlappingMaps')]
    public function it_rejects_a_unit_reported_in_two_maps(array $translations, array $failures, array $permanentFailures): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unit "u1" is reported more than once');

        new BatchResult($translations, $failures, $permanentFailures);
    }

    #[Test]
    public function it_rejects_a_permanent_failure_without_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"u3"');

        new BatchResult([], [], ['u3' => '']);
    }
}
