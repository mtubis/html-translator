<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit;

use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\FallbackStrategy;
use HtmlTranslator\Progress;
use HtmlTranslator\TranslationOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslationOptions::class)]
#[UsesClass(Locale::class)]
#[UsesClass(InvalidArgumentException::class)]
final class TranslationOptionsTest extends TestCase
{
    #[Test]
    public function it_has_safe_defaults(): void
    {
        $options = new TranslationOptions();

        self::assertNull($options->source);
        self::assertSame(FallbackStrategy::KeepOriginal, $options->fallback);
        self::assertSame(2, $options->maxCorrectiveRetries);
        self::assertSame(20, $options->batchSize);
        self::assertNull($options->onProgress);
    }

    #[Test]
    public function it_holds_given_values(): void
    {
        $onProgress = static function (Progress $progress): void {};
        $options = new TranslationOptions(Locale::fromString('en'), FallbackStrategy::Throw, 0, 1, $onProgress);

        self::assertSame('en', $options->source?->toString());
        self::assertSame(FallbackStrategy::Throw, $options->fallback);
        self::assertSame(0, $options->maxCorrectiveRetries);
        self::assertSame(1, $options->batchSize);
        self::assertSame($onProgress, $options->onProgress);
    }

    #[Test]
    public function it_rejects_negative_retries(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TranslationOptions(maxCorrectiveRetries: -1);
    }

    #[Test]
    public function it_rejects_an_empty_batch_size(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TranslationOptions(batchSize: 0);
    }
}
