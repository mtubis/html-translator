<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Profile;

use Closure;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Profile\Formality;
use HtmlTranslator\Profile\Glossary;
use HtmlTranslator\Profile\GlossaryEntry;
use HtmlTranslator\Profile\ProfileText;
use HtmlTranslator\Profile\TermPolicy;
use HtmlTranslator\Profile\TranslationProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslationProfile::class)]
#[CoversClass(ProfileText::class)]
#[UsesClass(Glossary::class)]
#[UsesClass(GlossaryEntry::class)]
final class TranslationProfileTest extends TestCase
{
    #[Test]
    public function it_has_nothing_set_by_default(): void
    {
        $profile = TranslationProfile::default();

        self::assertNull($profile->domain);
        self::assertSame(Formality::Default, $profile->formality);
        self::assertSame(TermPolicy::TranslateAll, $profile->termPolicy);
        self::assertTrue($profile->glossary->isEmpty());
        self::assertSame([], $profile->doNotTranslate);
        self::assertNull($profile->instructions);
    }

    /**
     * @return iterable<string, array{TranslationProfile, string, Formality, TermPolicy, ?string}>
     */
    public static function presets(): iterable
    {
        yield 'technical' => [TranslationProfile::technical(), 'software and technical documentation', Formality::Default, TermPolicy::PreferOriginalTechnicalTerms, null];
        yield 'marketing' => [
            TranslationProfile::marketing(),
            'marketing copy',
            Formality::Default,
            TermPolicy::TranslateAll,
            'Adapt idioms and tone for the target audience instead of translating literally. Keep the length of the text close to the source.',
        ];
        yield 'legal' => [
            TranslationProfile::legal(),
            'legal texts',
            Formality::Formal,
            TermPolicy::TranslateAll,
            'Translate precisely. Do not paraphrase or summarise. Translate defined terms consistently.',
        ];
    }

    #[Test]
    #[DataProvider('presets')]
    public function it_configures_presets_as_documented(
        TranslationProfile $profile,
        string $domain,
        Formality $formality,
        TermPolicy $termPolicy,
        ?string $instructions,
    ): void {
        self::assertSame($domain, $profile->domain);
        self::assertSame($formality, $profile->formality);
        self::assertSame($termPolicy, $profile->termPolicy);
        self::assertSame($instructions, $profile->instructions);
        self::assertTrue($profile->glossary->isEmpty());
        self::assertSame([], $profile->doNotTranslate);
    }

    /**
     * @return iterable<string, array{Closure(TranslationProfile): TranslationProfile, Closure(TranslationProfile): mixed, mixed}>
     */
    public static function withers(): iterable
    {
        $glossary = Glossary::fromArray(['commit' => 'commit']);

        yield 'domain' => [
            static fn(TranslationProfile $p): TranslationProfile => $p->withDomain('medical devices'),
            static fn(TranslationProfile $p): ?string => $p->domain,
            'medical devices',
        ];
        yield 'formality' => [
            static fn(TranslationProfile $p): TranslationProfile => $p->withFormality(Formality::Informal),
            static fn(TranslationProfile $p): Formality => $p->formality,
            Formality::Informal,
        ];
        yield 'term policy' => [
            static fn(TranslationProfile $p): TranslationProfile => $p->withTermPolicy(TermPolicy::KeepOriginal),
            static fn(TranslationProfile $p): TermPolicy => $p->termPolicy,
            TermPolicy::KeepOriginal,
        ];
        yield 'glossary' => [
            static fn(TranslationProfile $p): TranslationProfile => $p->withGlossary($glossary),
            static fn(TranslationProfile $p): Glossary => $p->glossary,
            $glossary,
        ];
        yield 'do not translate' => [
            static fn(TranslationProfile $p): TranslationProfile => $p->withDoNotTranslate('GitHub', 'Symfony'),
            static fn(TranslationProfile $p): array => $p->doNotTranslate,
            ['GitHub', 'Symfony'],
        ];
        yield 'instructions' => [
            static fn(TranslationProfile $p): TranslationProfile => $p->withInstructions('Address the reader directly.'),
            static fn(TranslationProfile $p): ?string => $p->instructions,
            'Address the reader directly.',
        ];
    }

    /**
     * @param Closure(TranslationProfile): TranslationProfile $wither
     * @param Closure(TranslationProfile): mixed $read
     */
    #[Test]
    #[DataProvider('withers')]
    public function it_returns_a_new_profile_from_every_wither_and_keeps_the_original(Closure $wither, Closure $read, mixed $expected): void
    {
        $original = TranslationProfile::technical();
        $before = $read($original);

        $changed = $wither($original);

        self::assertNotSame($original, $changed);
        self::assertSame($expected, $read($changed));
        self::assertSame($before, $read($original));
        self::assertEquals(TranslationProfile::technical(), $original);
    }

    #[Test]
    public function it_keeps_other_fields_when_one_is_changed(): void
    {
        $profile = TranslationProfile::legal()->withDoNotTranslate('ACME');

        self::assertSame('legal texts', $profile->domain);
        self::assertSame(Formality::Formal, $profile->formality);
        self::assertSame(TranslationProfile::legal()->instructions, $profile->instructions);
        self::assertSame(['ACME'], $profile->doNotTranslate);
    }

    #[Test]
    public function it_replaces_the_do_not_translate_list(): void
    {
        $profile = TranslationProfile::default()->withDoNotTranslate('GitHub')->withDoNotTranslate('Symfony');

        self::assertSame(['Symfony'], $profile->doNotTranslate);
        self::assertSame([], $profile->withDoNotTranslate()->doNotTranslate);
    }

    #[Test]
    public function it_lists_do_not_translate_terms_spread_with_string_keys(): void
    {
        $profile = TranslationProfile::default()->withDoNotTranslate(...['first' => 'GitHub', 'second' => 'Symfony']);

        self::assertSame(['GitHub', 'Symfony'], $profile->doNotTranslate);
    }

    #[Test]
    public function it_allows_line_breaks_in_instructions(): void
    {
        $profile = TranslationProfile::default()->withInstructions("Address the reader directly.\nUse short sentences.");

        self::assertSame("Address the reader directly.\nUse short sentences.", $profile->instructions);
    }

    /**
     * @return iterable<string, array{Closure(TranslationProfile): TranslationProfile}>
     */
    public static function glossaryAndDoNotTranslateOverlaps(): iterable
    {
        yield 'glossary first' => [static fn(TranslationProfile $p): TranslationProfile => $p->withGlossary(Glossary::fromArray(['żółw' => 'turtle']))->withDoNotTranslate('ŻÓŁW')];
        yield 'do-not-translate first' => [static fn(TranslationProfile $p): TranslationProfile => $p->withDoNotTranslate('żółw')->withGlossary(Glossary::fromArray(['ŻÓŁW' => 'turtle']))];
    }

    /**
     * @param Closure(TranslationProfile): TranslationProfile $configure
     */
    #[Test]
    #[DataProvider('glossaryAndDoNotTranslateOverlaps')]
    public function it_rejects_a_term_in_both_the_glossary_and_the_do_not_translate_list(Closure $configure): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('both a glossary term and a do-not-translate term');

        $configure(TranslationProfile::default());
    }

    #[Test]
    public function it_clears_domain_and_instructions_with_null(): void
    {
        $profile = TranslationProfile::marketing()->withDomain(null)->withInstructions(null);

        self::assertNull($profile->domain);
        self::assertNull($profile->instructions);
    }

    #[Test]
    public function it_trims_text_fields(): void
    {
        $profile = TranslationProfile::default()
            ->withDomain("  medical devices\n")
            ->withInstructions("\tAddress the reader directly. ")
            ->withDoNotTranslate(' GitHub ');

        self::assertSame('medical devices', $profile->domain);
        self::assertSame('Address the reader directly.', $profile->instructions);
        self::assertSame(['GitHub'], $profile->doNotTranslate);
    }

    #[Test]
    public function it_accepts_text_fields_up_to_their_limits(): void
    {
        $profile = TranslationProfile::default()
            ->withDomain(str_repeat('ż', 200))
            ->withInstructions(str_repeat('ż', 2000))
            ->withDoNotTranslate(...self::terms(500));

        self::assertSame(200, mb_strlen((string) $profile->domain));
        self::assertSame(2000, mb_strlen((string) $profile->instructions));
        self::assertCount(500, $profile->doNotTranslate);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidDomains(): iterable
    {
        yield 'empty' => ['', 'Domain'];
        yield 'whitespace only' => [" \n\t", 'Domain'];
        yield 'non-breaking spaces only' => ["\u{00A0}\u{00A0}", 'Domain'];
        yield 'line break' => ["medical\ndevices", 'line breaks'];
        yield 'over the limit' => [str_repeat('ż', 201), '200 characters'];
        yield 'invalid UTF-8' => ["caf\xE9", 'UTF-8'];
    }

    #[Test]
    #[DataProvider('invalidDomains')]
    public function it_rejects_invalid_domain(string $domain, string $messagePart): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($messagePart);

        TranslationProfile::default()->withDomain($domain);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidInstructions(): iterable
    {
        yield 'empty' => ['', 'Instructions'];
        yield 'whitespace only' => ['   ', 'Instructions'];
        yield 'ideographic spaces only' => ["\u{3000}", 'Instructions'];
        yield 'over the limit' => [str_repeat('a', 2001), '2000 characters'];
        yield 'invalid UTF-8' => ["\xC3\x28", 'UTF-8'];
    }

    #[Test]
    #[DataProvider('invalidInstructions')]
    public function it_rejects_invalid_instructions(string $instructions, string $messagePart): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($messagePart);

        TranslationProfile::default()->withInstructions($instructions);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidDoNotTranslateTerms(): iterable
    {
        yield 'blank term' => [['GitHub', ' '], 'Do-not-translate term'];
        yield 'term over the limit' => [[str_repeat('a', 201)], '200 characters'];
        yield 'invalid UTF-8' => [["caf\xE9"], 'UTF-8'];
        yield 'case-insensitive duplicate' => [['GitHub', 'github'], 'more than once'];
        yield 'case-insensitive multibyte duplicate' => [['Żółw', 'żółw'], 'more than once'];
        yield 'duplicate after trimming Unicode whitespace' => [['GitHub', "GitHub\u{00A0}"], 'more than once'];
        yield 'line break' => [["Git\nHub"], 'line breaks'];
        yield 'too many terms' => [self::terms(501), '500'];
    }

    /**
     * @param list<string> $terms
     */
    #[Test]
    #[DataProvider('invalidDoNotTranslateTerms')]
    public function it_rejects_invalid_do_not_translate_terms(array $terms, string $messagePart): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($messagePart);

        TranslationProfile::default()->withDoNotTranslate(...$terms);
    }

    /**
     * @return list<string>
     */
    private static function terms(int $count): array
    {
        return array_map(static fn(int $i): string => 'Term ' . $i, range(1, $count));
    }
}
