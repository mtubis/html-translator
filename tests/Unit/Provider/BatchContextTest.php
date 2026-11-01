<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Provider;

use HtmlTranslator\Exception\ArrayArgument;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\UntrustedText;
use HtmlTranslator\Provider\BatchContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BatchContext::class)]
#[UsesClass(ArrayArgument::class)]
#[UsesClass(UntrustedText::class)]
final class BatchContextTest extends TestCase
{
    #[Test]
    public function it_has_nothing_without_context(): void
    {
        $context = BatchContext::none();

        self::assertTrue($context->isEmpty());
        self::assertNull($context->title);
        self::assertSame([], $context->headings);
        self::assertSame([], $context->unitHeadings);
    }

    #[Test]
    public function it_lists_distinct_headings_in_order_of_first_use(): void
    {
        $context = BatchContext::forUnits('Docs', ['u1' => null, 'u2' => 'Install', 'u3' => 'Install', 'a1' => 'Use', 'u4' => 'Install']);

        self::assertFalse($context->isEmpty());
        self::assertSame('Docs', $context->title);
        self::assertSame(['Install', 'Use'], $context->headings);
        self::assertSame(['u2' => 0, 'u3' => 0, 'a1' => 1, 'u4' => 0], $context->unitHeadings);
    }

    #[Test]
    public function it_has_context_with_a_title_only(): void
    {
        $context = BatchContext::forUnits('Docs', ['u1' => null]);

        self::assertFalse($context->isEmpty());
        self::assertSame([], $context->headings);
    }

    #[Test]
    public function it_is_empty_without_title_and_headings(): void
    {
        self::assertTrue(BatchContext::forUnits(null, ['u1' => null, 'u2' => null])->isEmpty());
    }

    #[Test]
    public function it_selects_the_context_of_some_units(): void
    {
        $document = BatchContext::forUnits('Docs', ['u1' => 'Install', 'u2' => 'Install', 'u3' => 'Use', 'u4' => null]);

        $selected = $document->select(['u3' => 'u3', 'u2-1' => 'u2', 'u4' => 'u4']);

        self::assertSame('Docs', $selected->title);
        self::assertSame(['Use', 'Install'], $selected->headings);
        self::assertSame(['u3' => 0, 'u2-1' => 1], $selected->unitHeadings);
    }

    #[Test]
    public function it_treats_an_empty_title_or_heading_as_none(): void
    {
        $context = BatchContext::forUnits('', ['u1' => '', 'u2' => null]);

        self::assertTrue($context->isEmpty());
        self::assertSame([], $context->unitHeadings);
    }

    #[Test]
    public function it_rejects_a_heading_that_is_not_a_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('headingByUnit["u1"] must be a string or null, got int.');

        // @phpstan-ignore argument.type (deliberately wrong input: the runtime check is what is under test)
        BatchContext::forUnits('Title', ['u1' => 5]);
    }
}
