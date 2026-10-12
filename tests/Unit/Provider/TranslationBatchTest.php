<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Provider;

use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Domain\LocalePair;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Domain\UnitKind;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Profile\Glossary;
use HtmlTranslator\Profile\ProfileText;
use HtmlTranslator\Profile\TranslationProfile;
use HtmlTranslator\Provider\BatchContext;
use HtmlTranslator\Provider\Correction;
use HtmlTranslator\Provider\TranslationBatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslationBatch::class)]
#[CoversClass(Correction::class)]
#[UsesClass(BatchContext::class)]
#[UsesClass(Locale::class)]
#[UsesClass(LocalePair::class)]
#[UsesClass(TranslationUnit::class)]
#[UsesClass(UnitId::class)]
#[UsesClass(InvalidArgumentException::class)]
#[UsesClass(TranslationProfile::class)]
#[UsesClass(Glossary::class)]
#[UsesClass(ProfileText::class)]
final class TranslationBatchTest extends TestCase
{
    #[Test]
    public function it_holds_units_and_corrections(): void
    {
        $correction = new Correction('<g1>x', '- Close <g1> with </g1>.');
        $batch = new TranslationBatch($this->locales(), [$this->unit('u1'), $this->unit('u2')], ['u2' => $correction]);

        self::assertSame(['u1', 'u2'], array_map(static fn(TranslationUnit $unit): string => $unit->id->toString(), $batch->units));
        self::assertSame($correction, $batch->corrections['u2'] ?? null);
        self::assertSame('<g1>x', $correction->previousTranslation);
        self::assertSame('- Close <g1> with </g1>.', $correction->problems);
        self::assertSame('pl', $batch->locales->target->toString());
    }

    #[Test]
    public function it_carries_the_default_profile_unless_given_one(): void
    {
        $profile = TranslationProfile::legal();

        self::assertEquals(TranslationProfile::default(), new TranslationBatch($this->locales(), [$this->unit('u1')])->profile);
        self::assertSame($profile, new TranslationBatch($this->locales(), [$this->unit('u1')], profile: $profile)->profile);
    }

    #[Test]
    public function it_carries_no_context_unless_given_one(): void
    {
        $context = BatchContext::forUnits('Docs', ['u1' => 'Install']);

        self::assertEquals(BatchContext::none(), new TranslationBatch($this->locales(), [$this->unit('u1')])->context);
        self::assertSame($context, new TranslationBatch($this->locales(), [$this->unit('u1')], context: $context)->context);
    }

    #[Test]
    public function it_rejects_context_for_a_unit_outside_the_batch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"u9"');

        new TranslationBatch($this->locales(), [$this->unit('u1')], context: BatchContext::forUnits(null, ['u9' => 'Install']));
    }

    #[Test]
    public function it_rejects_an_empty_batch(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TranslationBatch($this->locales(), []);
    }

    #[Test]
    public function it_rejects_a_correction_for_a_unit_outside_the_batch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"u9"');

        new TranslationBatch($this->locales(), [$this->unit('u1')], ['u9' => new Correction('x', 'y')]);
    }

    #[Test]
    public function it_rejects_duplicate_unit_ids(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"u1"');

        new TranslationBatch($this->locales(), [$this->unit('u1'), $this->unit('u1')]);
    }

    private function locales(): LocalePair
    {
        return LocalePair::of(Locale::fromString('en'), Locale::fromString('pl'));
    }

    private function unit(string $id): TranslationUnit
    {
        return TranslationUnit::fromText(UnitId::fromString($id), UnitKind::Block, 'x');
    }
}
