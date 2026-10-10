<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Provider;

use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Provider\BatchResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BatchResult::class)]
#[UsesClass(InvalidArgumentException::class)]
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
    public function it_rejects_a_unit_that_is_both_translated_and_failed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"u1"');

        new BatchResult(['u1' => 'Jeden'], ['u1' => 'refused']);
    }

    #[Test]
    public function it_rejects_a_failure_without_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"u2"');

        new BatchResult([], ['u2' => ' ']);
    }
}
