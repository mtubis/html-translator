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
use HtmlTranslator\Profile\Formality;
use HtmlTranslator\Profile\Glossary;
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
 * HtmlTranslator with a unit cache (ARCHITECTURE §6.7, ADR-0016); everything else is in HtmlTranslatorTest.
 */
#[CoversClass(HtmlTranslator::class)]
#[CoversClass(TranslationStats::class)]
#[UsesClass(UnitCache::class)]
#[UsesClass(CacheKey::class)]
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
#[UsesClass(TermChecker::class)]
#[UsesClass(TermMatcher::class)]
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
final class HtmlTranslatorCacheTest extends TestCase
{
    private const string HTML = '<p>One <b>bold</b> line</p><p>Two</p><img alt="Three">';

    private InMemoryCachePool $pool;

    protected function setUp(): void
    {
        $this->pool = new InMemoryCachePool();
    }

    #[Test]
    public function it_calls_the_provider_once_for_the_same_document_translated_twice(): void
    {
        $provider = $this->pseudoProvider();
        $translator = new HtmlTranslator($provider, cache: $this->pool);

        $first = $translator->translate(self::HTML, Locale::fromString('pl'));
        $second = $translator->translate(self::HTML, Locale::fromString('pl'));

        self::assertCount(1, $provider->batches);
        self::assertSame($first->html(), $second->html());
        self::assertTrue($second->isComplete());
        self::assertSame(0, $first->stats()->cacheHits);
        self::assertSame(3, $second->stats()->units);
        self::assertSame(3, $second->stats()->cacheHits);
        self::assertSame(0, $second->stats()->batches);
        self::assertNull($second->stats()->inputTokens);
    }

    #[Test]
    public function it_sends_only_the_units_that_are_not_cached(): void
    {
        $provider = $this->pseudoProvider();
        $translator = new HtmlTranslator($provider, cache: $this->pool);
        $translator->translate(self::HTML, Locale::fromString('pl'));

        $result = $translator->translate('<p>One <b>bold</b> line</p><p>Two, edited</p><img alt="Three">', Locale::fromString('pl'));

        self::assertSame(['u1', 'u2', 'a1', 'u2'], $provider->sentUnitIds());
        self::assertSame(2, $result->stats()->cacheHits);
        self::assertSame(1, $result->stats()->batches);
        self::assertStringContainsString('<p>[!! Öñé <b>ƀöļð</b> ļíñé !!]</p><p>[!! Ţŵö, éðíţéð !!]</p>', $result->html());
    }

    #[Test]
    public function it_reuses_a_translation_with_the_surrounding_whitespace_of_the_new_unit(): void
    {
        $provider = $this->pseudoProvider();
        $translator = new HtmlTranslator($provider, cache: $this->pool);
        $translator->translate('<p>Two</p>', Locale::fromString('pl'));

        $result = $translator->translate("<div>\n  Two </div>", Locale::fromString('pl'));

        self::assertCount(1, $provider->batches);
        self::assertSame("<div>\n  [!! Ţŵö !!] </div>", $result->html());
    }

    #[Test]
    public function it_counts_cached_units_as_done_in_the_progress(): void
    {
        $translator = new HtmlTranslator($this->pseudoProvider(), cache: $this->pool);
        $translator->translate('<p>Two</p>', Locale::fromString('pl'));

        $progress = [];
        $translator->translate(self::HTML, Locale::fromString('pl'), new TranslationOptions(onProgress: static function (Progress $p) use (&$progress): void {
            $progress[] = $p;
        }));

        self::assertEquals([new Progress(1, 1, 3, 3)], $progress);
    }

    #[Test]
    public function it_reports_completion_once_when_every_unit_comes_from_the_cache(): void
    {
        $translator = new HtmlTranslator($this->pseudoProvider(), cache: $this->pool);
        $translator->translate(self::HTML, Locale::fromString('pl'));

        $progress = [];
        $translator->translate(self::HTML, Locale::fromString('pl'), new TranslationOptions(onProgress: static function (Progress $p) use (&$progress): void {
            $progress[] = $p;
        }));

        self::assertEquals([new Progress(0, 0, 3, 3)], $progress);
    }

    #[Test]
    public function it_reports_no_progress_for_a_document_without_units(): void
    {
        $progress = [];
        $result = new HtmlTranslator($this->pseudoProvider(), cache: $this->pool)->translate('<p><br></p>', Locale::fromString('pl'), new TranslationOptions(onProgress: static function (Progress $p) use (&$progress): void {
            $progress[] = $p;
        }));

        self::assertSame(0, $result->stats()->units);
        self::assertSame([], $progress);
    }

    #[Test]
    public function it_caches_a_translation_that_became_valid_in_a_corrective_retry(): void
    {
        $provider = CacheableCallbackProvider::delegatingTo(PseudoLocalizationProvider::corruptingEvery(1));
        $translator = new HtmlTranslator($provider, cache: $this->pool);
        $translator->translate('<p>One <b>bold</b> line</p>', Locale::fromString('pl'));
        $provider->batches = [];

        $result = $translator->translate('<p>One <b>bold</b> line</p>', Locale::fromString('pl'));

        self::assertSame([], $provider->batches);
        self::assertSame(1, $result->stats()->cacheHits);
        self::assertSame('<p>[!! Öñé <b>ƀöļð</b> ļíñé !!]</p>', $result->html());
    }

    #[Test]
    public function it_misses_the_cache_when_the_profile_changes(): void
    {
        $provider = $this->pseudoProvider();
        $translator = new HtmlTranslator($provider, cache: $this->pool);
        $translator->translate(self::HTML, Locale::fromString('pl'));

        $result = $translator->translate(self::HTML, Locale::fromString('pl'), new TranslationOptions(profile: TranslationProfile::default()->withFormality(Formality::Formal)));

        self::assertCount(2, $provider->batches);
        self::assertSame(0, $result->stats()->cacheHits);
    }

    #[Test]
    public function it_misses_the_cache_when_the_locale_pair_changes(): void
    {
        $provider = $this->pseudoProvider();
        $translator = new HtmlTranslator($provider, cache: $this->pool);
        $translator->translate(self::HTML, Locale::fromString('pl'));

        $translator->translate(self::HTML, Locale::fromString('de'));
        $translator->translate(self::HTML, Locale::fromString('pl'), new TranslationOptions(source: Locale::fromString('en')));

        self::assertCount(3, $provider->batches);
    }

    #[Test]
    public function it_misses_the_cache_when_the_provider_id_changes(): void
    {
        $first = $this->pseudoProvider('llm:fake:model-a:2026-10.4');
        new HtmlTranslator($first, cache: $this->pool)->translate(self::HTML, Locale::fromString('pl'));

        foreach (['llm:fake:model-b:2026-10.4', 'llm:fake:model-a:2026-10.5'] as $cacheId) {
            $other = $this->pseudoProvider($cacheId);
            $result = new HtmlTranslator($other, cache: $this->pool)->translate(self::HTML, Locale::fromString('pl'));

            self::assertCount(1, $other->batches, $cacheId);
            self::assertSame(0, $result->stats()->cacheHits, $cacheId);
        }
    }

    #[Test]
    public function it_does_not_cache_units_that_kept_their_original_text(): void
    {
        // u2 is refused for good, a1 stays invalid however often it is retried.
        $pseudo = PseudoLocalizationProvider::corruptingEvery(1, persistent: true);
        $provider = new CacheableCallbackProvider(static function (TranslationBatch $batch) use ($pseudo): BatchResult {
            $result = $pseudo->translate($batch);
            $translations = array_intersect_key($result->translations, ['a1' => true]);
            if (isset($result->translations['u1'])) {
                $translations['u1'] = '[!! Öñé <g1>ƀöļð</g1> ļíñé !!]';
            }

            return new BatchResult($translations, permanentFailures: isset($result->translations['u2']) ? ['u2' => 'Refused.'] : []);
        });
        $translator = new HtmlTranslator($provider, cache: $this->pool);
        $options = new TranslationOptions(fallback: FallbackStrategy::KeepOriginal, maxCorrectiveRetries: 1);
        $translator->translate(self::HTML, Locale::fromString('pl'), $options);
        $provider->batches = [];

        $result = $translator->translate(self::HTML, Locale::fromString('pl'), $options);

        self::assertSame(['u2', 'a1', 'a1'], $provider->sentUnitIds());
        self::assertSame(1, $result->stats()->cacheHits);
        self::assertFalse($result->isComplete());
    }

    #[Test]
    public function it_does_not_cache_the_output_of_the_text_node_fallback(): void
    {
        $provider = CacheableCallbackProvider::delegatingTo(PseudoLocalizationProvider::corruptingEvery(1, persistent: true));
        $translator = new HtmlTranslator($provider, cache: $this->pool);
        $options = new TranslationOptions(fallback: FallbackStrategy::TextNodesOnly, maxCorrectiveRetries: 0);
        $first = $translator->translate('<p>One <b>bold</b> line</p>', Locale::fromString('pl'), $options);
        $provider->batches = [];

        $second = $translator->translate('<p>One <b>bold</b> line</p>', Locale::fromString('pl'), $options);

        self::assertSame(['u1', 'u1-1', 'u1-2', 'u1-3'], $provider->sentUnitIds());
        self::assertSame(0, $second->stats()->cacheHits);
        self::assertSame($first->html(), $second->html());
    }

    #[Test]
    public function it_keeps_translations_of_earlier_batches_when_a_later_batch_throws(): void
    {
        $pseudo = new PseudoLocalizationProvider();
        $failing = new CacheableCallbackProvider(static function (TranslationBatch $batch) use ($pseudo): BatchResult {
            if ('u2' === ($batch->units[0] ?? null)?->id->toString()) {
                throw new ProviderException('Service unavailable.');
            }

            return $pseudo->translate($batch);
        }, 'pseudo');

        try {
            new HtmlTranslator($failing, cache: $this->pool)->translate(self::HTML, Locale::fromString('pl'), new TranslationOptions(batchSize: 1));
            self::fail('The provider exception should propagate.');
        } catch (ProviderException) {
        }

        $provider = $this->pseudoProvider();
        $result = new HtmlTranslator($provider, cache: $this->pool)->translate(self::HTML, Locale::fromString('pl'));

        self::assertSame(['u2', 'a1'], $provider->sentUnitIds());
        self::assertSame(1, $result->stats()->cacheHits);
    }

    #[Test]
    public function it_translates_again_when_a_cache_entry_is_not_a_string(): void
    {
        $provider = $this->pseudoProvider();
        $translator = new HtmlTranslator($provider, cache: $this->pool);
        $translator->translate('<p>Two</p>', Locale::fromString('pl'));
        $this->corruptEveryEntry(42);

        $result = $translator->translate('<p>Two</p>', Locale::fromString('pl'));

        self::assertCount(2, $provider->batches);
        self::assertSame(0, $result->stats()->cacheHits);
        self::assertSame('<p>[!! Ţŵö !!]</p>', $result->html());
        self::assertSame(['[!! Ţŵö !!]'], array_values($this->pool->values));
    }

    #[Test]
    public function it_translates_again_when_a_cache_entry_has_an_invalid_structure(): void
    {
        $provider = $this->pseudoProvider();
        $translator = new HtmlTranslator($provider, cache: $this->pool);
        $translator->translate('<p>One <b>bold</b> line</p>', Locale::fromString('pl'));
        $this->corruptEveryEntry('<script>alert(1)</script>');

        $result = $translator->translate('<p>One <b>bold</b> line</p>', Locale::fromString('pl'));

        self::assertCount(2, $provider->batches);
        self::assertSame(0, $result->stats()->cacheHits);
        self::assertSame('<p>[!! Öñé <b>ƀöļð</b> ļíñé !!]</p>', $result->html());
        self::assertSame(['[!! Öñé <g1>ƀöļð</g1> ļíñé !!]'], array_values($this->pool->values));
    }

    #[Test]
    public function it_does_not_cache_for_a_provider_that_is_not_cacheable(): void
    {
        $pseudo = new PseudoLocalizationProvider();
        $provider = new CallbackProvider($pseudo->translate(...));
        $logger = new CollectingLogger();
        $translator = new HtmlTranslator($provider, cache: $this->pool, logger: $logger);

        $translator->translate(self::HTML, Locale::fromString('pl'));
        $result = $translator->translate(self::HTML, Locale::fromString('pl'));

        self::assertCount(2, $provider->batches);
        self::assertSame(0, $result->stats()->cacheHits);
        self::assertSame([], $this->pool->values);
        self::assertCount(2, $logger->records);
        [$level, $message] = $logger->records[0];
        self::assertSame('notice', $level);
        self::assertStringContainsString('CacheableProviderInterface', $message);
    }

    #[Test]
    public function it_does_not_need_a_logger_for_a_provider_that_is_not_cacheable(): void
    {
        $result = new HtmlTranslator(new CallbackProvider(new PseudoLocalizationProvider()->translate(...)), cache: $this->pool)->translate(self::HTML, Locale::fromString('pl'));

        self::assertTrue($result->isComplete());
        self::assertSame([], $this->pool->values);
    }

    #[Test]
    public function it_does_not_log_about_the_cache_without_one(): void
    {
        $logger = new CollectingLogger();

        $result = new HtmlTranslator(new CallbackProvider(new PseudoLocalizationProvider()->translate(...)), logger: $logger)->translate(self::HTML, Locale::fromString('pl'));

        self::assertSame([], $logger->records);
        self::assertSame(0, $result->stats()->cacheHits);
    }

    private function pseudoProvider(string $cacheId = 'pseudo'): CacheableCallbackProvider
    {
        return CacheableCallbackProvider::delegatingTo(new PseudoLocalizationProvider(), $cacheId);
    }

    private function corruptEveryEntry(mixed $value): void
    {
        self::assertNotSame([], $this->pool->values);
        foreach (array_keys($this->pool->values) as $key) {
            $this->pool->values[$key] = $value;
        }
    }
}
