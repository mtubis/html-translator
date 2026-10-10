<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Provider\Testing;

use HtmlTranslator\Domain\InlineTag;
use HtmlTranslator\Domain\InlineTagKind;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Domain\LocalePair;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Domain\UnitKind;
use HtmlTranslator\Domain\UnitText;
use HtmlTranslator\Domain\UnitTextToken;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Provider\BatchResult;
use HtmlTranslator\Provider\Correction;
use HtmlTranslator\Provider\Testing\PseudoLocalizationProvider;
use HtmlTranslator\Provider\TranslationBatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PseudoLocalizationProvider::class)]
#[UsesClass(BatchResult::class)]
#[UsesClass(TranslationBatch::class)]
#[UsesClass(Correction::class)]
#[UsesClass(Locale::class)]
#[UsesClass(LocalePair::class)]
#[UsesClass(TranslationUnit::class)]
#[UsesClass(UnitId::class)]
#[UsesClass(InlineTag::class)]
#[UsesClass(UnitText::class)]
#[UsesClass(UnitTextToken::class)]
#[UsesClass(InvalidArgumentException::class)]
final class PseudoLocalizationProviderTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function transformations(): iterable
    {
        yield 'architecture example' => ['Hello <g1>world</g1>', '[!! Ĥéļļö <g1>ŵöŕļð</g1> !!]'];
        yield 'every ASCII letter' => [
            'abcdefghijklmnopqrstuvwxyz ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            '[!! áƀçðéƒĝĥíĵķļɱñöþǫŕšţüṽŵẋýž ÁƁÇĐÉƑĜĤÍĴĶĻṀÑÖÞǪŔŠŢÜṼŴẊÝŽ !!]',
        ];
        yield 'void and nested tags' => ['a<x1/>b <g2>c <g3>d</g3></g2>', '[!! á<x1/>ƀ <g2>ç <g3>ð</g3></g2> !!]'];
        yield 'escapes stay escaped' => ['Tom &amp; Jerry &lt;3', '[!! Ţöɱ &amp; Ĵéŕŕý &lt;3 !!]'];
        yield 'tag spelling is kept' => ['a<x1 />', '[!! á<x1 /> !!]'];
        yield 'raw markup in the input is passed through' => ['a <b>c', '[!! á <b>ç !!]'];
        yield 'digits, punctuation and non-ASCII are kept' => ['2026: Zażółć, 日本 😀!', '[!! 2026: Žáżółć, 日本 😀! !!]'];
    }

    #[Test]
    #[DataProvider('transformations')]
    public function it_pseudo_localizes_deterministically(string $text, string $expected): void
    {
        $provider = new PseudoLocalizationProvider();
        $batch = $this->batch([$this->unit('u1', $text)]);

        self::assertSame(['u1' => $expected], $provider->translate($batch)->translations);
        self::assertSame(['u1' => $expected], $provider->translate($batch)->translations);
    }

    #[Test]
    public function it_translates_plain_text_units(): void
    {
        $unit = TranslationUnit::fromText(UnitId::fromString('a1'), UnitKind::Attribute, 'Logo');

        self::assertSame(['a1' => '[!! Ļöĝö !!]'], new PseudoLocalizationProvider()->translate($this->batch([$unit]))->translations);
    }

    #[Test]
    public function it_corrupts_every_kth_unit_by_its_number_on_the_first_attempt(): void
    {
        $provider = PseudoLocalizationProvider::corruptingEvery(2);
        $batch = $this->batch([
            $this->unit('u1', 'a <g1>b</g1>'),
            $this->unit('u2', 'c <g1>d</g1><x2/>'),
            TranslationUnit::fromText(UnitId::fromString('a4'), UnitKind::Attribute, 'e'),
        ]);

        self::assertSame(
            ['u1' => '[!! á <g1>ƀ</g1> !!]', 'u2' => '[!! ç <g1>ð</g1> !!]', 'a4' => ''],
            $provider->translate($batch)->translations,
        );
    }

    #[Test]
    public function it_translates_corrupted_units_correctly_when_retried(): void
    {
        $provider = PseudoLocalizationProvider::corruptingEvery(2);
        $retry = $this->batch([$this->unit('u2', 'c <g1>d</g1>')], ['u2' => new Correction('[!! ç <g1>ð !!]', '- Close <g1> with </g1>.')]);

        self::assertSame(['u2' => '[!! ç <g1>ð</g1> !!]'], $provider->translate($retry)->translations);
    }

    #[Test]
    public function it_keeps_corrupting_retries_when_persistent(): void
    {
        $provider = PseudoLocalizationProvider::corruptingEvery(1, persistent: true);
        $retry = $this->batch([$this->unit('u2', 'c <g1>d</g1>')], ['u2' => new Correction('x', 'y')]);

        self::assertSame(['u2' => '[!! ç <g1>ð !!]'], $provider->translate($retry)->translations);
    }

    #[Test]
    public function it_never_corrupts_derived_units(): void
    {
        $provider = PseudoLocalizationProvider::corruptingEvery(1, persistent: true);
        $batch = $this->batch([TranslationUnit::fromText(UnitId::fromString('u3-1'), UnitKind::Block, 'Click')]);

        self::assertSame(['u3-1' => '[!! Çļíçķ !!]'], $provider->translate($batch)->translations);
    }

    #[Test]
    public function it_reports_no_failures(): void
    {
        self::assertSame([], PseudoLocalizationProvider::corruptingEvery(1)->translate($this->batch([$this->unit('u1', 'a')]))->failures);
    }

    #[Test]
    public function it_rejects_a_negative_interval_in_the_constructor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PseudoLocalizationProvider(-2);
    }

    #[Test]
    public function it_rejects_persistent_corruption_without_an_interval(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PseudoLocalizationProvider(0, true);
    }

    #[Test]
    public function it_rejects_a_corruption_interval_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PseudoLocalizationProvider::corruptingEvery(0);
    }

    /**
     * @param list<TranslationUnit> $units
     * @param array<string, Correction> $corrections
     */
    private function batch(array $units, array $corrections = []): TranslationBatch
    {
        return new TranslationBatch(LocalePair::of(Locale::fromString('en'), Locale::fromString('pl')), $units, $corrections);
    }

    private function unit(string $id, string $text): TranslationUnit
    {
        $tags = [];
        foreach (UnitText::tokenize($text) as $token) {
            if (null !== $token->number && !str_starts_with($token->text, '</')) {
                $tags[] = InlineTag::of($token->number, str_starts_with($token->text, '<g') ? InlineTagKind::Paired : InlineTagKind::Void);
            }
        }

        return TranslationUnit::fromText(UnitId::fromString($id), UnitKind::Block, $text, $tags);
    }
}
