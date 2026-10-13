<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Validation;

use HtmlTranslator\Domain\InlineTag;
use HtmlTranslator\Domain\InlineTagKind;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Domain\UnitKind;
use HtmlTranslator\Domain\UnitText;
use HtmlTranslator\Domain\UnitTextToken;
use HtmlTranslator\Exception\UntrustedText;
use HtmlTranslator\Profile\Glossary;
use HtmlTranslator\Profile\GlossaryEntry;
use HtmlTranslator\Profile\ProfileText;
use HtmlTranslator\Profile\TermMatcher;
use HtmlTranslator\Profile\TranslationProfile;
use HtmlTranslator\Validation\TermChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TermChecker::class)]
#[UsesClass(TranslationUnit::class)]
#[UsesClass(UnitId::class)]
#[UsesClass(InlineTag::class)]
#[UsesClass(UnitText::class)]
#[UsesClass(UnitTextToken::class)]
#[UsesClass(TranslationProfile::class)]
#[UsesClass(Glossary::class)]
#[UsesClass(GlossaryEntry::class)]
#[UsesClass(ProfileText::class)]
#[UsesClass(TermMatcher::class)]
#[UsesClass(UntrustedText::class)]
final class TermCheckerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function keptTerms(): iterable
    {
        yield 'kept' => ['Push it to GitHub.', 'Wypchnij to na GitHub.'];
        yield 'kept, inflected around' => ['Use GitHub Actions.', 'Użyj GitHub Actions.'];
        yield 'kept across a moved tag' => ['Open <g1>GitHub</g1> now.', 'Otwórz teraz <g1>GitHub</g1>.'];
        // The reader still sees "Symfony Flex"; a tag between the words of a term does not break it (ADR-0012).
        yield 'kept, split by a tag' => ['Use <g1>Symfony Flex</g1>.', 'Użyj Symfony <g1>Flex</g1>.'];
        yield 'escaped term' => ['Call AT&amp;T.', 'Zadzwoń do AT&amp;T.'];
        yield 'not in the source' => ['Contact us.', 'Skontaktuj się z nami.'];
        yield 'source in another case, translation in that case' => ['PUSH TO GITHUB', 'WYPCHNIJ NA GITHUB'];
        yield 'source in another case, translation in profile case' => ['PUSH TO GITHUB', 'WYPCHNIJ NA GitHub'];
    }

    #[Test]
    #[DataProvider('keptTerms')]
    public function it_accepts_translations_that_keep_the_do_not_translate_terms(string $source, string $translation): void
    {
        self::assertSame([], $this->check($source, $translation, $this->profile()));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function brokenTerms(): iterable
    {
        yield 'translated' => ['Push it to GitHub.', 'Wypchnij to na GitHuba.', ['GitHub']];
        yield 'other case' => ['Push it to GitHub.', 'Wypchnij to na Github.', ['GitHub']];
        yield 'words joined' => ['Use <g1>Symfony Flex</g1>.', 'Użyj <g1>SymfonyFlex</g1>.', ['Symfony Flex']];
        yield 'gone in a source written in another case' => ['PUSH TO GITHUB', 'WYPCHNIJ NA SERWER', ['GitHub']];
        yield 'two terms, in profile order' => ['Symfony Flex on GitHub and AT&amp;T', 'Flex na serwerze i AT i T', ['Symfony Flex', 'GitHub', 'AT&T']];
    }

    /**
     * @param list<string> $terms
     */
    #[Test]
    #[DataProvider('brokenTerms')]
    public function it_names_every_do_not_translate_term_the_translation_breaks(string $source, string $translation, array $terms): void
    {
        self::assertSame(
            array_map(static fn(string $term): string => \sprintf('the do-not-translate term "%s" is missing or changed', $term), $terms),
            $this->check($source, $translation, $this->profile()),
        );
    }

    #[Test]
    public function it_checks_glossary_entries_that_keep_their_term(): void
    {
        $profile = TranslationProfile::default()->withGlossary(Glossary::fromArray(['pull request' => 'Pull Request']));

        self::assertSame([], $this->check('Open a pull request.', 'Otwórz pull request.', $profile));
        self::assertSame(
            ['the glossary term "pull request" should stay "Pull Request"'],
            $this->check('Open a pull request.', 'Otwórz żądanie scalenia.', $profile),
        );
    }

    #[Test]
    public function it_compares_glossary_source_and_target_ignoring_multibyte_case(): void
    {
        $profile = TranslationProfile::default()->withGlossary(Glossary::fromArray(['ŻÓŁW' => 'żółw']));

        self::assertSame(['the glossary term "ŻÓŁW" should stay "żółw"'], $this->check('UWAGA: ŻÓŁW', 'UWAGA: ŻABA', $profile));
        self::assertSame(
            ['the glossary term "żółw" should stay "ŻÓŁW"'],
            $this->check('Uwaga: żółw', 'Uwaga: żaba', TranslationProfile::default()->withGlossary(Glossary::fromArray(['żółw' => 'ŻÓŁW']))),
        );
    }

    #[Test]
    public function it_does_not_check_glossary_entries_that_translate_their_term(): void
    {
        // The model may inflect a target term (ADR-0012): "gałąź" becomes "gałęzi" in Polish.
        $profile = TranslationProfile::default()->withGlossary(Glossary::fromArray(['branch' => 'gałąź']));

        self::assertSame([], $this->check('Switch to another branch.', 'Przełącz się do innej gałęzi.', $profile));
        self::assertSame([], $this->check('Switch to another branch.', 'Przełącz się na inny branch.', $profile));
    }

    #[Test]
    public function it_checks_attribute_units(): void
    {
        $unit = TranslationUnit::fromText(UnitId::fromString('a1'), UnitKind::Attribute, 'GitHub logo');

        self::assertSame(['the do-not-translate term "GitHub" is missing or changed'], new TermChecker()->check($unit, 'Logo GitHuba', $this->profile()));
    }

    #[Test]
    public function it_finds_nothing_with_the_default_profile(): void
    {
        self::assertSame([], $this->check('Push it to GitHub.', 'Wypchnij to na GitHuba.', TranslationProfile::default()));
    }

    /**
     * @return list<string>
     */
    private function check(string $source, string $translation, TranslationProfile $profile): array
    {
        $tags = [];
        preg_match_all('/<g(\d+)>/', $source, $matches);
        foreach ($matches[1] as $number) {
            $tags[] = InlineTag::of((int) $number, InlineTagKind::Paired);
        }

        return new TermChecker()->check(TranslationUnit::fromText(UnitId::fromString('u1'), UnitKind::Block, $source, $tags), $translation, $profile);
    }

    private function profile(): TranslationProfile
    {
        return TranslationProfile::default()->withDoNotTranslate('Symfony Flex', 'GitHub', 'AT&T');
    }
}
