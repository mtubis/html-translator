<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit;

use HtmlTranslator\Batching\Batcher;
use HtmlTranslator\Batching\CharacterTokenEstimator;
use HtmlTranslator\Cache\CacheKey;
use HtmlTranslator\Cache\UnitCache;
use HtmlTranslator\Domain\InlineTag;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Domain\LocalePair;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Domain\UnitText;
use HtmlTranslator\Domain\UnitTextToken;
use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Exception\UntrustedText;
use HtmlTranslator\FallbackStrategy;
use HtmlTranslator\Html\AttributeAnchor;
use HtmlTranslator\Html\EncodedRun;
use HtmlTranslator\Html\HtmlLoader;
use HtmlTranslator\Html\InlineEncoder;
use HtmlTranslator\Html\LoadedDocument;
use HtmlTranslator\Html\Reassembler;
use HtmlTranslator\Html\RunAnchor;
use HtmlTranslator\Html\SegmentationContext;
use HtmlTranslator\Html\SegmentedDocument;
use HtmlTranslator\Html\Segmenter;
use HtmlTranslator\Html\SkipRules;
use HtmlTranslator\Html\TranslatableAttributes;
use HtmlTranslator\HtmlTranslator;
use HtmlTranslator\Issue;
use HtmlTranslator\IssueType;
use HtmlTranslator\Profile\Glossary;
use HtmlTranslator\Profile\GlossaryEntry;
use HtmlTranslator\Profile\ProfileText;
use HtmlTranslator\Profile\TermMatcher;
use HtmlTranslator\Profile\TranslationProfile;
use HtmlTranslator\Progress;
use HtmlTranslator\Provider\BatchContext;
use HtmlTranslator\Provider\BatchResult;
use HtmlTranslator\Provider\Correction;
use HtmlTranslator\Provider\Testing\PseudoLocalizationProvider;
use HtmlTranslator\Provider\TokenUsage;
use HtmlTranslator\Provider\TranslationBatch;
use HtmlTranslator\Tests\Support\CacheableCallbackProvider;
use HtmlTranslator\Tests\Support\CallbackProvider;
use HtmlTranslator\Tests\Support\CollectingLogger;
use HtmlTranslator\Tests\Support\InMemoryCachePool;
use HtmlTranslator\TranslatedDocument;
use HtmlTranslator\TranslationOptions;
use HtmlTranslator\TranslationStats;
use HtmlTranslator\Validation\StructureValidator;
use HtmlTranslator\Validation\TagNesting;
use HtmlTranslator\Validation\TermChecker;
use HtmlTranslator\Validation\ValidationResult;
use HtmlTranslator\Validation\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * HtmlTranslator checking translations against the profile's terms (ADR-0017); everything else is in HtmlTranslatorTest.
 */
#[CoversClass(HtmlTranslator::class)]
#[UsesClass(TranslationStats::class)]
#[UsesClass(TermChecker::class)]
#[UsesClass(UnitCache::class)]
#[UsesClass(CacheKey::class)]
#[UsesClass(TermMatcher::class)]
#[UsesClass(GlossaryEntry::class)]
#[UsesClass(TranslatedDocument::class)]
#[UsesClass(Progress::class)]
#[UsesClass(Issue::class)]
#[UsesClass(TranslationOptions::class)]
#[UsesClass(Batcher::class)]
#[UsesClass(CharacterTokenEstimator::class)]
#[UsesClass(PseudoLocalizationProvider::class)]
#[UsesClass(TranslationBatch::class)]
#[UsesClass(BatchResult::class)]
#[UsesClass(TokenUsage::class)]
#[UsesClass(BatchContext::class)]
#[UsesClass(Correction::class)]
#[UsesClass(HtmlLoader::class)]
#[UsesClass(LoadedDocument::class)]
#[UsesClass(Segmenter::class)]
#[UsesClass(SegmentationContext::class)]
#[UsesClass(SegmentedDocument::class)]
#[UsesClass(RunAnchor::class)]
#[UsesClass(AttributeAnchor::class)]
#[UsesClass(InlineEncoder::class)]
#[UsesClass(EncodedRun::class)]
#[UsesClass(Reassembler::class)]
#[UsesClass(SkipRules::class)]
#[UsesClass(TranslatableAttributes::class)]
#[UsesClass(StructureValidator::class)]
#[UsesClass(TagNesting::class)]
#[UsesClass(ValidationResult::class)]
#[UsesClass(Violation::class)]
#[UsesClass(Locale::class)]
#[UsesClass(LocalePair::class)]
#[UsesClass(TranslationUnit::class)]
#[UsesClass(UnitId::class)]
#[UsesClass(InlineTag::class)]
#[UsesClass(UnitText::class)]
#[UsesClass(UnitTextToken::class)]
#[UsesClass(ProviderException::class)]
#[UsesClass(TranslationProfile::class)]
#[UsesClass(Glossary::class)]
#[UsesClass(ProfileText::class)]
#[UsesClass(UntrustedText::class)]
final class HtmlTranslatorTermCheckTest extends TestCase
{
    private const string HTML = '<p>Push it to <b>GitHub</b>.</p><p>Open a pull request.</p>';

    #[Test]
    public function it_reports_a_translation_that_changes_a_do_not_translate_term(): void
    {
        $provider = $this->answering(['u1' => 'Wypchnij to na <g1>GitHuba</g1>.', 'u2' => 'Otwórz pull request.']);
        $logger = new CollectingLogger();

        $result = new HtmlTranslator($provider, logger: $logger)->translate(self::HTML, Locale::fromString('pl'), $this->options());

        self::assertTrue($result->isComplete());
        self::assertSame([['u1', IssueType::TermViolation, 'The translation of unit "u1" does not follow the translation profile: the do-not-translate term "GitHub" is missing or changed.']], $this->issues($result));
        self::assertStringContainsString('GitHuba', $result->html());
        self::assertSame('warning', $logger->records[0][0] ?? null);
    }

    #[Test]
    public function it_names_every_broken_term_of_a_unit_in_one_issue(): void
    {
        $provider = $this->answering(['u1' => 'Wypchnij to na <g1>GitHuba</g1>.', 'u2' => 'Otwórz żądanie scalenia.']);
        $html = '<p>Push it to <b>GitHub</b> with a pull request.</p><p>Open a pull request.</p>';

        $result = new HtmlTranslator($provider)->translate($html, Locale::fromString('pl'), $this->options());

        self::assertSame([
            ['u1', IssueType::TermViolation, 'The translation of unit "u1" does not follow the translation profile: the do-not-translate term "GitHub" is missing or changed; the glossary term "pull request" should stay "pull request".'],
            ['u2', IssueType::TermViolation, 'The translation of unit "u2" does not follow the translation profile: the glossary term "pull request" should stay "pull request".'],
        ], $this->issues($result));
    }

    #[Test]
    public function it_names_at_most_three_broken_terms_of_a_unit(): void
    {
        $provider = $this->answering(['u1' => 'Nic tu nie ma.']);
        $options = new TranslationOptions(profile: TranslationProfile::default()->withDoNotTranslate('Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'));

        $result = new HtmlTranslator($provider)->translate('<p>Alpha Beta Gamma Delta Epsilon</p>', Locale::fromString('pl'), $options);

        self::assertSame(
            'The translation of unit "u1" does not follow the translation profile: the do-not-translate term "Alpha" is missing or changed; the do-not-translate term "Beta" is missing or changed; the do-not-translate term "Gamma" is missing or changed; and 2 more.',
            $result->issues()[0]->message ?? null,
        );
    }

    #[Test]
    public function it_names_exactly_three_broken_terms_without_a_remainder(): void
    {
        $provider = $this->answering(['u1' => 'Nic tu nie ma.']);
        $options = new TranslationOptions(profile: TranslationProfile::default()->withDoNotTranslate('Alpha', 'Beta', 'Gamma'));

        $result = new HtmlTranslator($provider)->translate('<p>Alpha Beta Gamma</p>', Locale::fromString('pl'), $options);

        self::assertStringEndsWith('the do-not-translate term "Gamma" is missing or changed.', $result->issues()[0]->message ?? '');
    }

    #[Test]
    public function it_reports_nothing_when_every_term_is_kept_and_makes_no_extra_calls(): void
    {
        $provider = $this->answering(['u1' => 'Wypchnij to na <g1>GitHub</g1>.', 'u2' => 'Otwórz pull request.']);

        $result = new HtmlTranslator($provider)->translate(self::HTML, Locale::fromString('pl'), $this->options());

        self::assertSame([], $result->issues());
        self::assertCount(1, $provider->batches);
        self::assertSame(1, $result->stats()->batches);
        self::assertSame(0, $result->stats()->retries);
    }

    #[Test]
    public function it_does_not_retry_or_fall_back_for_a_broken_term(): void
    {
        $provider = $this->answering(['u1' => 'Wypchnij to na <g1>GitHuba</g1>.', 'u2' => 'Otwórz pull request.']);

        $result = new HtmlTranslator($provider)->translate(self::HTML, Locale::fromString('pl'), $this->options(FallbackStrategy::Throw));

        self::assertCount(1, $provider->batches);
        self::assertSame(0, $result->stats()->retries);
        self::assertCount(1, $result->issues());
    }

    #[Test]
    public function it_checks_translations_from_the_cache(): void
    {
        $provider = new CacheableCallbackProvider(static fn(): BatchResult => new BatchResult(['u1' => 'Wypchnij to na <g1>GitHuba</g1>.', 'u2' => 'Otwórz pull request.']));
        $translator = new HtmlTranslator($provider, cache: new InMemoryCachePool());
        $translator->translate(self::HTML, Locale::fromString('pl'), $this->options());

        $result = $translator->translate(self::HTML, Locale::fromString('pl'), $this->options());

        self::assertSame(2, $result->stats()->cacheHits);
        self::assertSame(['u1'], array_map(static fn(array $issue): string => $issue[0], $this->issues($result)));
    }

    #[Test]
    public function it_checks_the_output_of_the_text_node_fallback(): void
    {
        // u1 loses its tag, so its text nodes are sent one by one; "u1-2" is the text node inside <b>.
        $provider = $this->answering(['u1' => 'Wypchnij to na GitHub.', 'u2' => 'Otwórz pull request.', 'u1-1' => 'Wypchnij to na', 'u1-2' => 'GitHuba', 'u1-3' => '.']);

        $result = new HtmlTranslator($provider)->translate(self::HTML, Locale::fromString('pl'), $this->options(FallbackStrategy::TextNodesOnly, maxCorrectiveRetries: 0));

        self::assertSame([['u1', IssueType::InvalidStructure], ['u1', IssueType::TermViolation]], array_map(static fn(array $issue): array => [$issue[0], $issue[1]], $this->issues($result)));
    }

    #[Test]
    public function it_does_not_check_units_that_kept_their_original_text(): void
    {
        $provider = $this->answering(['u2' => 'Otwórz pull request.']);

        $result = new HtmlTranslator($provider)->translate(self::HTML, Locale::fromString('pl'), $this->options(maxCorrectiveRetries: 0));

        self::assertSame([['u1', IssueType::MissingTranslation]], array_map(static fn(array $issue): array => [$issue[0], $issue[1]], $this->issues($result)));
    }

    private function options(FallbackStrategy $fallback = FallbackStrategy::KeepOriginal, int $maxCorrectiveRetries = 2): TranslationOptions
    {
        return new TranslationOptions(
            fallback: $fallback,
            maxCorrectiveRetries: $maxCorrectiveRetries,
            profile: TranslationProfile::default()
                ->withDoNotTranslate('GitHub')
                ->withGlossary(Glossary::fromArray(['pull request' => 'pull request', 'branch' => 'gałąź'])),
        );
    }

    /**
     * @param array<string, string> $translations
     */
    private function answering(array $translations): CallbackProvider
    {
        return new CallbackProvider(static function (TranslationBatch $batch) use ($translations): BatchResult {
            $answer = [];
            foreach ($batch->units as $unit) {
                $id = $unit->id->toString();
                if (isset($translations[$id])) {
                    $answer[$id] = $translations[$id];
                }
            }

            return new BatchResult($answer);
        });
    }

    /**
     * @return list<array{string, IssueType, string}>
     */
    private function issues(TranslatedDocument $result): array
    {
        return array_map(static fn(Issue $issue): array => [$issue->unitId->toString(), $issue->type, $issue->message], $result->issues());
    }
}
