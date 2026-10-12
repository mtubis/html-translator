<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit;

use HtmlTranslator\Domain\InlineTag;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Domain\LocalePair;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Domain\UnitText;
use HtmlTranslator\Domain\UnitTextToken;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\InvalidHtmlException;
use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Exception\StructureValidationException;
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
use HtmlTranslator\Profile\Glossary;
use HtmlTranslator\Profile\ProfileText;
use HtmlTranslator\Profile\TranslationProfile;
use HtmlTranslator\Progress;
use HtmlTranslator\Provider\BatchContext;
use HtmlTranslator\Provider\BatchResult;
use HtmlTranslator\Provider\Correction;
use HtmlTranslator\Provider\Testing\PseudoLocalizationProvider;
use HtmlTranslator\Provider\TranslationBatch;
use HtmlTranslator\Tests\Support\CallbackProvider;
use HtmlTranslator\Tests\Support\CollectingLogger;
use HtmlTranslator\TranslatedDocument;
use HtmlTranslator\TranslationOptions;
use HtmlTranslator\TranslationStats;
use HtmlTranslator\Validation\StructureValidator;
use HtmlTranslator\Validation\TagNesting;
use HtmlTranslator\Validation\ValidationResult;
use HtmlTranslator\Validation\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HtmlTranslator::class)]
#[CoversClass(TranslatedDocument::class)]
#[CoversClass(TranslationStats::class)]
#[CoversClass(Progress::class)]
#[CoversClass(Issue::class)]
#[CoversClass(StructureValidationException::class)]
#[UsesClass(TranslationOptions::class)]
#[UsesClass(PseudoLocalizationProvider::class)]
#[UsesClass(TranslationBatch::class)]
#[UsesClass(BatchResult::class)]
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
#[UsesClass(InvalidArgumentException::class)]
#[UsesClass(InvalidHtmlException::class)]
#[UsesClass(ProviderException::class)]
#[UsesClass(TranslationProfile::class)]
#[UsesClass(Glossary::class)]
#[UsesClass(ProfileText::class)]
#[UsesClass(UntrustedText::class)]
final class HtmlTranslatorTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../Fixtures/roundtrip';

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtures(): iterable
    {
        $files = glob(self::FIXTURES . '/*.html');
        self::assertNotFalse($files);

        foreach ($files as $file) {
            yield basename($file, '.html') => [$file];
        }
    }

    #[Test]
    #[DataProvider('fixtures')]
    public function it_pseudo_localizes_every_fixture_end_to_end(string $file): void
    {
        $html = file_get_contents($file);
        self::assertIsString($html);

        $result = new HtmlTranslator(new PseudoLocalizationProvider())->translate($html, Locale::fromString('fr'));

        self::assertTrue($result->isComplete());
        self::assertSame([], $result->issues());

        $units = $this->segment($result->html())->units();
        self::assertSame(\count($units), $result->stats()->units);
        foreach ($units as $unit) {
            self::assertMatchesRegularExpression('/^\[!! .* !!\]$/s', $unit->text, \sprintf('Unit %s was not translated.', $unit->id));
        }

        if (!str_starts_with(basename($file), 'fragment-')) {
            self::assertStringContainsString('lang="fr"', $result->html());
        }
    }

    #[Test]
    public function it_retries_units_that_fail_validation(): void
    {
        $provider = new CallbackProvider(static fn(TranslationBatch $batch): BatchResult => PseudoLocalizationProvider::corruptingEvery(2)->translate($batch));

        $result = new HtmlTranslator($provider)->translate('<p>A</p><p>B <b>c</b></p><p>D</p><p>E</p>', Locale::fromString('pl'));

        self::assertTrue($result->isComplete());
        self::assertSame([], $result->issues());
        self::assertSame('<p>[!! Á !!]</p><p>[!! Ɓ <b>ç</b> !!]</p><p>[!! Đ !!]</p><p>[!! É !!]</p>', $result->html());
        self::assertSame(2, $result->stats()->retries);

        $retry = $provider->batches[1] ?? self::fail('No corrective retry.');
        self::assertSame(['u2', 'u4'], array_map(static fn(TranslationUnit $unit): string => $unit->id->toString(), $retry->units));
        $correction = $retry->corrections['u2'] ?? self::fail('No correction for u2.');
        self::assertSame('[!! Ɓ <g1>ç !!]', $correction->previousTranslation);
        self::assertSame('- Close <g1> with </g1>.', $correction->problems);
    }

    #[Test]
    public function it_keeps_the_original_of_units_that_keep_failing(): void
    {
        $html = '<p>Hello <b>world</b></p><p>Bye</p>';

        $result = new HtmlTranslator(PseudoLocalizationProvider::corruptingEvery(1, persistent: true))
            ->translate($html, Locale::fromString('pl'), new TranslationOptions(fallback: FallbackStrategy::KeepOriginal));

        self::assertSame($html, $result->html());
        self::assertFalse($result->isComplete());
        self::assertSame(4, $result->stats()->retries);
        self::assertSame(
            [
                ['u1', 'InvalidStructure', "The translation of unit \"u1\" is still invalid after 2 corrective retries:\n- Close <g1> with </g1>."],
                ['u2', 'InvalidStructure', "The translation of unit \"u2\" is still invalid after 2 corrective retries:\n- Translate the text; the translation must not be empty."],
            ],
            $this->issues($result),
        );
    }

    #[Test]
    public function it_translates_text_nodes_separately_as_a_fallback(): void
    {
        $result = new HtmlTranslator($this->brokenUnitsWithTranslatedPieces())
            ->translate('<p>Click <a href="/x">here</a> now</p>', Locale::fromString('pl'), new TranslationOptions(fallback: FallbackStrategy::TextNodesOnly));

        self::assertSame('<p>[!! Çļíçķ !!] <a href="/x">[!! ĥéŕé !!]</a> [!! ñöŵ !!]</p>', $result->html());
        self::assertTrue($result->isComplete());
        self::assertSame([['u1', 'InvalidStructure', "The translation of unit \"u1\" is still invalid after 2 corrective retries:\n- Close <g1> with </g1>.\nIts text nodes were sent for translation separately."]], $this->issues($result));
    }

    #[Test]
    public function it_saves_units_of_the_corrupting_provider_with_the_text_node_fallback(): void
    {
        $result = new HtmlTranslator(PseudoLocalizationProvider::corruptingEvery(1, persistent: true))
            ->translate('<p>Click <a href="/x">here</a> now</p><img src="a.png" alt="Logo">', Locale::fromString('pl'), new TranslationOptions(fallback: FallbackStrategy::TextNodesOnly));

        self::assertSame('<p>[!! Çļíçķ !!] <a href="/x">[!! ĥéŕé !!]</a> [!! ñöŵ !!]</p><img src="a.png" alt="[!! Ļöĝö !!]">', $result->html());
        self::assertTrue($result->isComplete());
        self::assertSame(['u1', 'a1'], array_map(static fn(Issue $issue): string => $issue->unitId->toString(), $result->issues()));
    }

    #[Test]
    public function it_keeps_text_nodes_that_fail_in_the_fallback(): void
    {
        $result = new HtmlTranslator($this->brokenUnitsWithTranslatedPieces(failingPiece: 'u1-2'))
            ->translate('<p>Click <a href="/x">here</a> now</p>', Locale::fromString('pl'), new TranslationOptions(fallback: FallbackStrategy::TextNodesOnly));

        self::assertSame('<p>[!! Çļíçķ !!] <a href="/x">here</a> [!! ñöŵ !!]</p>', $result->html());
        self::assertFalse($result->isComplete());
        self::assertSame(
            [
                ['u1', 'InvalidStructure', "The translation of unit \"u1\" is still invalid after 2 corrective retries:\n- Close <g1> with </g1>.\nIts text nodes were sent for translation separately."],
                ['u1', 'PartiallyTranslated', 'Unit "u1": 1 of 3 text nodes kept their original text (refused).'],
            ],
            $this->issues($result),
        );
    }

    #[Test]
    public function it_combines_results_of_all_batches(): void
    {
        $result = new HtmlTranslator(PseudoLocalizationProvider::corruptingEvery(2, persistent: true))
            ->translate('<p>A</p><p>B</p><p>C</p><p>D</p>', Locale::fromString('pl'), new TranslationOptions(batchSize: 1));

        self::assertSame('<p>[!! Á !!]</p><p>B</p><p>[!! Ç !!]</p><p>D</p>', $result->html());
        self::assertSame(['u2', 'u4'], array_map(static fn(Issue $issue): string => $issue->unitId->toString(), $result->issues()));
        self::assertSame(4, $result->stats()->retries);
        self::assertLessThan(60.0, $result->stats()->durationSeconds);
    }

    #[Test]
    public function it_combines_text_node_fallbacks_of_several_units_and_batches(): void
    {
        $options = new TranslationOptions(fallback: FallbackStrategy::TextNodesOnly, batchSize: 2);

        $result = new HtmlTranslator($this->brokenUnitsWithTranslatedPieces())
            ->translate('<p>One <b>a</b></p><p>Two <b>b</b></p><p>Three <b>c</b></p>', Locale::fromString('pl'), $options);

        self::assertSame('<p>[!! Öñé !!] <b>[!! á !!]</b></p><p>[!! Ţŵö !!] <b>[!! ƀ !!]</b></p><p>[!! Ţĥŕéé !!] <b>[!! ç !!]</b></p>', $result->html());
        self::assertSame(['u1', 'u2', 'u3'], array_map(static fn(Issue $issue): string => $issue->unitId->toString(), $result->issues()));
        self::assertTrue($result->isComplete());
    }

    #[Test]
    public function it_names_each_reason_once_when_several_text_nodes_fail(): void
    {
        $provider = new CallbackProvider(static function (TranslationBatch $batch): BatchResult {
            $failures = [];
            foreach ($batch->units as $unit) {
                $failures[$unit->id->toString()] = 'refused';
            }

            return new BatchResult([], $failures);
        });

        $result = new HtmlTranslator($provider)
            ->translate('<p>Click <a href="/x">here</a> now</p>', Locale::fromString('pl'), new TranslationOptions(fallback: FallbackStrategy::TextNodesOnly, maxCorrectiveRetries: 0));

        self::assertSame('<p>Click <a href="/x">here</a> now</p>', $result->html());
        self::assertSame('Unit "u1": 3 of 3 text nodes kept their original text (refused).', ($result->issues()[1] ?? null)?->message);
    }

    #[Test]
    public function it_keeps_good_units_of_a_batch_in_which_others_fail(): void
    {
        $provider = new CallbackProvider(static fn(TranslationBatch $batch): BatchResult => new BatchResult(
            array_diff_key(new PseudoLocalizationProvider()->translate($batch)->translations, ['u1' => true]),
            isset($batch->corrections['u1']) || 1 !== \count($batch->units) ? ['u1' => 'refused'] : [],
        ));

        $result = new HtmlTranslator($provider)->translate('<p>A</p><p>B</p>', Locale::fromString('pl'), new TranslationOptions(maxCorrectiveRetries: 0));

        self::assertSame('<p>A</p><p>[!! Ɓ !!]</p>', $result->html());
        self::assertSame([['u1', 'ProviderFailure', 'The provider could not translate unit "u1": refused']], $this->issues($result));
    }

    #[Test]
    public function it_throws_when_the_fallback_is_throw(): void
    {
        $this->expectException(StructureValidationException::class);
        $this->expectExceptionMessage('"u1"');

        new HtmlTranslator(PseudoLocalizationProvider::corruptingEvery(1, persistent: true))
            ->translate('<p>Hello <b>world</b></p>', Locale::fromString('pl'), new TranslationOptions(fallback: FallbackStrategy::Throw));
    }

    /**
     * @return iterable<string, array{BatchResult, string}>
     */
    public static function providerProblems(): iterable
    {
        yield 'refused unit' => [new BatchResult([], ['u1' => 'content policy']), 'The provider could not translate unit "u1": content policy'];
        yield 'missing unit' => [new BatchResult([]), 'The provider returned no translation for unit "u1".'];
    }

    #[Test]
    #[DataProvider('providerProblems')]
    public function it_throws_a_provider_exception_for_provider_problems_when_the_fallback_is_throw(BatchResult $answer, string $message): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage($message);

        new HtmlTranslator(new CallbackProvider(static fn(TranslationBatch $batch): BatchResult => $answer))
            ->translate('<p>Hello</p>', Locale::fromString('pl'), new TranslationOptions(fallback: FallbackStrategy::Throw));
    }

    #[Test]
    public function it_keeps_earlier_batches_when_the_provider_fails_during_a_retry(): void
    {
        $provider = new CallbackProvider(static fn(TranslationBatch $batch): BatchResult => [] === $batch->corrections
            ? PseudoLocalizationProvider::corruptingEvery(2)->translate($batch)
            : throw new ProviderException('Service unavailable.'));

        $result = new HtmlTranslator($provider)->translate('<p>A</p><p>B</p>', Locale::fromString('pl'));

        self::assertSame('<p>[!! Á !!]</p><p>B</p>', $result->html());
        self::assertSame([['u2', 'ProviderFailure', 'The provider could not translate unit "u2": Service unavailable.']], $this->issues($result));
        self::assertCount(2, $provider->batches, 'No further retries after the provider failed.');
        self::assertSame(1, $result->stats()->retries);
    }

    #[Test]
    public function it_keeps_the_original_text_when_the_provider_fails_during_the_text_node_fallback(): void
    {
        $provider = new CallbackProvider(static fn(TranslationBatch $batch): BatchResult => array_any($batch->units, static fn(TranslationUnit $unit): bool => str_contains($unit->id->toString(), '-'))
            ? throw new ProviderException('Service unavailable.')
            : new BatchResult(['u1' => 'Kliknij <g1>tutaj']));

        $result = new HtmlTranslator($provider)
            ->translate('<p>Click <a href="/x">here</a></p>', Locale::fromString('pl'), new TranslationOptions(fallback: FallbackStrategy::TextNodesOnly, maxCorrectiveRetries: 0));

        self::assertSame('<p>Click <a href="/x">here</a></p>', $result->html());
        self::assertFalse($result->isComplete());
        self::assertSame('Unit "u1": 2 of 2 text nodes kept their original text (Service unavailable.).', ($result->issues()[1] ?? null)?->message);
    }

    #[Test]
    public function it_ignores_translations_for_ids_outside_the_batch(): void
    {
        $provider = new CallbackProvider(static fn(TranslationBatch $batch): BatchResult => new BatchResult(['u1' => '[!! Á !!]', 'u9' => 'x', 'a1' => 'y']));

        $result = new HtmlTranslator($provider)->translate('<p>A</p>', Locale::fromString('pl'));

        self::assertSame('<p>[!! Á !!]</p>', $result->html());
        self::assertSame([], $result->issues());
    }

    #[Test]
    public function it_records_provider_failures(): void
    {
        $provider = new CallbackProvider(static fn(TranslationBatch $batch): BatchResult => new BatchResult([], ['u1' => 'content policy']));

        $result = new HtmlTranslator($provider)->translate('<p>Hello</p>', Locale::fromString('pl'), new TranslationOptions(maxCorrectiveRetries: 1));

        self::assertSame('<p>Hello</p>', $result->html());
        self::assertSame([['u1', 'ProviderFailure', 'The provider could not translate unit "u1": content policy']], $this->issues($result));
        self::assertCount(2, $provider->batches);
        self::assertSame([], $provider->batches[1]->corrections, 'Refused units are re-sent without a correction.');
    }

    /**
     * Answers by attempt: $script[attempt] maps unit id → 'timeout' | 'policy' | 'throw'; other units are translated.
     *
     * @param list<array<string, string>> $script
     */
    private function scriptedProvider(array $script): CallbackProvider
    {
        $attempt = 0;

        return new CallbackProvider(static function (TranslationBatch $batch) use ($script, &$attempt): BatchResult {
            $answers = $script[$attempt++] ?? [];
            $translations = [];
            $failures = [];
            $permanent = [];

            foreach ($batch->units as $unit) {
                $id = $unit->id->toString();
                match ($answers[$id] ?? 'ok') {
                    'timeout' => $failures[$id] = 'timeout',
                    'policy' => $permanent[$id] = 'content policy',
                    'throw' => throw ProviderException::fromHttpResponse(503, 'Service unavailable.', 'overloaded'),
                    default => $translations += new PseudoLocalizationProvider()->translate(new TranslationBatch($batch->locales, [$unit]))->translations,
                };
            }

            return new BatchResult($translations, $failures, $permanent);
        });
    }

    /**
     * @return list<list<string>>
     */
    private function sentUnits(CallbackProvider $provider): array
    {
        return array_map(
            static fn(TranslationBatch $batch): array => array_map(static fn(TranslationUnit $unit): string => $unit->id->toString(), $batch->units),
            $provider->batches,
        );
    }

    #[Test]
    public function it_does_not_retry_permanent_failures(): void
    {
        $provider = $this->scriptedProvider([['u1' => 'timeout', 'u2' => 'policy'], ['u1' => 'timeout'], ['u1' => 'timeout']]);

        $result = new HtmlTranslator($provider)->translate('<p>A</p><p>B</p>', Locale::fromString('pl'));

        self::assertSame([['u1', 'u2'], ['u1'], ['u1']], $this->sentUnits($provider));
        self::assertSame(2, $result->stats()->retries);
        self::assertSame(
            [
                ['u1', 'ProviderFailure', 'The provider could not translate unit "u1": timeout'],
                ['u2', 'ProviderFailure', 'The provider could not translate unit "u2" (permanent failure): content policy'],
            ],
            $this->issues($result),
            'Issues follow document order.',
        );
    }

    #[Test]
    public function it_stops_retrying_a_unit_that_fails_permanently_on_a_retry(): void
    {
        $provider = $this->scriptedProvider([['u1' => 'timeout'], ['u1' => 'policy']]);

        $result = new HtmlTranslator($provider)->translate('<p>A</p>', Locale::fromString('pl'));

        self::assertSame([['u1'], ['u1']], $this->sentUnits($provider));
        self::assertSame(1, $result->stats()->retries);
        self::assertSame([['u1', 'ProviderFailure', 'The provider could not translate unit "u1" (permanent failure): content policy']], $this->issues($result));
    }

    #[Test]
    public function it_keeps_permanent_failures_when_the_provider_throws_on_a_retry(): void
    {
        $provider = $this->scriptedProvider([['u1' => 'policy', 'u2' => 'timeout'], ['u2' => 'throw']]);

        $result = new HtmlTranslator($provider)->translate('<p>A</p><p>B</p>', Locale::fromString('pl'));

        self::assertSame(
            [
                ['u1', 'ProviderFailure', 'The provider could not translate unit "u1" (permanent failure): content policy'],
                ['u2', 'ProviderFailure', 'The provider could not translate unit "u2": Service unavailable.'],
            ],
            $this->issues($result),
        );
    }

    #[Test]
    public function it_throws_a_permanent_failure_without_retrying_when_the_fallback_is_throw(): void
    {
        $provider = $this->scriptedProvider([['u1' => 'policy']]);

        try {
            new HtmlTranslator($provider)->translate('<p>A</p>', Locale::fromString('pl'), new TranslationOptions(fallback: FallbackStrategy::Throw));
            self::fail('Expected exception was not thrown.');
        } catch (ProviderException $exception) {
            self::assertSame('The provider could not translate unit "u1" (permanent failure): content policy', $exception->getMessage());
        }

        self::assertCount(1, $provider->batches);
    }

    #[Test]
    public function it_keeps_the_provider_details_when_a_retry_fails_and_the_fallback_is_throw(): void
    {
        $provider = $this->scriptedProvider([['u1' => 'timeout'], ['u1' => 'throw']]);

        try {
            new HtmlTranslator($provider)->translate('<p>A</p>', Locale::fromString('pl'), new TranslationOptions(fallback: FallbackStrategy::Throw));
            self::fail('Expected exception was not thrown.');
        } catch (ProviderException $exception) {
            self::assertSame('The provider could not translate unit "u1": Service unavailable.', $exception->getMessage());
            $cause = $exception->getPrevious();
            self::assertInstanceOf(ProviderException::class, $cause);
            self::assertSame(503, $cause->statusCode);
            self::assertSame('overloaded', $cause->vendorCode);
        }
    }

    #[Test]
    public function it_reports_permanent_failures_of_text_nodes_in_the_fallback(): void
    {
        $provider = $this->scriptedProvider([['u1' => 'policy'], ['u1-1' => 'policy']]);

        $result = new HtmlTranslator($provider)->translate('<p>Hello <b>world</b></p>', Locale::fromString('pl'), new TranslationOptions(fallback: FallbackStrategy::TextNodesOnly));

        self::assertSame('<p>Hello <b>[!! ŵöŕļð !!]</b></p>', $result->html());
        self::assertSame('Unit "u1": 1 of 2 text nodes kept their original text (content policy).', ($result->issues()[1] ?? null)?->message);
    }

    #[Test]
    public function it_records_units_the_provider_left_out(): void
    {
        $provider = new CallbackProvider(static fn(TranslationBatch $batch): BatchResult => new BatchResult([]));

        $result = new HtmlTranslator($provider)->translate('<p>Hello</p>', Locale::fromString('pl'), new TranslationOptions(maxCorrectiveRetries: 0));

        self::assertSame([['u1', 'MissingTranslation', 'The provider returned no translation for unit "u1".']], $this->issues($result));
        self::assertFalse($result->isComplete());
    }

    #[Test]
    public function it_tells_the_provider_which_units_it_left_out(): void
    {
        $provider = new CallbackProvider(static fn(TranslationBatch $batch): BatchResult => [] === $batch->corrections
            ? new BatchResult(['u2' => '[!! Ɓ !!]'])
            : new PseudoLocalizationProvider()->translate($batch));

        $result = new HtmlTranslator($provider)->translate('<p>A</p><p>B</p>', Locale::fromString('pl'));

        self::assertSame('<p>[!! Á !!]</p><p>[!! Ɓ !!]</p>', $result->html());
        self::assertSame([], $result->issues());

        $retry = $provider->batches[1] ?? self::fail('No retry.');
        self::assertSame(['u1'], array_map(static fn(TranslationUnit $unit): string => $unit->id->toString(), $retry->units));
        $correction = $retry->corrections['u1'] ?? self::fail('No correction for the missing unit.');
        self::assertSame('', $correction->previousTranslation);
        self::assertSame('- Translate the text; it was missing from your previous answer.', $correction->problems);
    }

    #[Test]
    public function it_gives_corrections_to_missing_and_broken_units_but_not_to_refused_ones(): void
    {
        $provider = new CallbackProvider(static fn(TranslationBatch $batch): BatchResult => [] === $batch->corrections
            ? new BatchResult(['u3' => '[!! Ç <g1>ð !!]'], ['u1' => 'content policy'])
            : new PseudoLocalizationProvider()->translate($batch));

        new HtmlTranslator($provider)->translate('<p>A</p><p>B</p><p>C <b>d</b></p>', Locale::fromString('pl'));

        $retry = $provider->batches[1] ?? self::fail('No retry.');
        self::assertSame(['u1', 'u2', 'u3'], array_map(static fn(TranslationUnit $unit): string => $unit->id->toString(), $retry->units));
        self::assertSame(['u2', 'u3'], array_keys($retry->corrections));
        self::assertEquals(Correction::forMissingUnit(), $retry->corrections['u2'] ?? null);
        self::assertSame('[!! Ç <g1>ð !!]', ($retry->corrections['u3'] ?? null)?->previousTranslation);
    }

    #[Test]
    public function it_reports_progress_once_per_batch(): void
    {
        $provider = new CallbackProvider(static fn(TranslationBatch $batch): BatchResult => new PseudoLocalizationProvider()->translate($batch));
        $progress = [];

        $result = new HtmlTranslator($provider)->translate(
            '<p>A</p><p>B</p><p>C</p><p>D</p><p>E</p>',
            Locale::fromString('pl'),
            new TranslationOptions(batchSize: 2, onProgress: static function (Progress $p) use (&$progress): void {
                $progress[] = [$p->batchesDone, $p->batchesTotal, $p->unitsDone, $p->unitsTotal];
            }),
        );

        self::assertSame([[1, 3, 2, 5], [2, 3, 4, 5], [3, 3, 5, 5]], $progress);
        self::assertSame([2, 2, 1], array_map(static fn(TranslationBatch $batch): int => \count($batch->units), $provider->batches));
        self::assertSame(3, $result->stats()->batches);
        self::assertSame(5, $result->stats()->units);
        self::assertSame(0, $result->stats()->retries);
        self::assertGreaterThanOrEqual(0.0, $result->stats()->durationSeconds);
    }

    #[Test]
    public function it_passes_the_profile_to_every_provider_call(): void
    {
        $profile = TranslationProfile::technical();
        $provider = new CallbackProvider(static fn(TranslationBatch $batch): BatchResult => PseudoLocalizationProvider::corruptingEvery(1, persistent: true)->translate($batch));

        new HtmlTranslator($provider)->translate(
            '<p>Hello <b>world</b></p>',
            Locale::fromString('pl'),
            new TranslationOptions(fallback: FallbackStrategy::TextNodesOnly, maxCorrectiveRetries: 1, profile: $profile),
        );

        self::assertCount(3, $provider->batches, 'first call, corrective retry, text-node fallback');
        foreach ($provider->batches as $batch) {
            self::assertSame($profile, $batch->profile);
        }
    }

    #[Test]
    public function it_passes_document_context_to_every_provider_call(): void
    {
        $provider = new CallbackProvider(static fn(TranslationBatch $batch): BatchResult => PseudoLocalizationProvider::corruptingEvery(1, persistent: true)->translate($batch));
        $html = '<!DOCTYPE html><title>Shop</title><h2>Plans</h2><p>Pick <b>one</b></p><h2>Support</h2><p>Mail <i>us</i></p>';

        new HtmlTranslator($provider)->translate($html, Locale::fromString('pl'), new TranslationOptions(fallback: FallbackStrategy::TextNodesOnly, maxCorrectiveRetries: 1));

        [$first, $retry, $fallback] = $provider->batches + [null, null, null];
        self::assertInstanceOf(TranslationBatch::class, $first);
        self::assertInstanceOf(TranslationBatch::class, $retry);
        self::assertInstanceOf(TranslationBatch::class, $fallback);
        self::assertCount(3, $provider->batches, 'first call, corrective retry, text-node fallback');

        self::assertSame('Shop', $first->context->title);
        self::assertSame(['Plans', 'Support'], $first->context->headings);
        // u4 is the heading "Support" itself, so it belongs to the section before it.
        self::assertSame(['u3' => 0, 'u4' => 0, 'u5' => 1], $first->context->unitHeadings);

        self::assertSame('Shop', $retry->context->title);
        self::assertSame(['Plans', 'Support'], $retry->context->headings);
        self::assertSame(['u3' => 0, 'u4' => 0, 'u5' => 1], $retry->context->unitHeadings);

        self::assertSame('Shop', $fallback->context->title);
        self::assertSame(['Plans', 'Support'], $fallback->context->headings);
        self::assertSame(0, $fallback->context->unitHeadings['u3-1'] ?? null, 'a text node inherits the heading of its unit');
        self::assertSame(1, $fallback->context->unitHeadings['u5-1'] ?? null);
        self::assertArrayNotHasKey('u1-1', $fallback->context->unitHeadings);
    }

    #[Test]
    public function it_sends_only_the_headings_of_the_units_retried(): void
    {
        $provider = new CallbackProvider(static fn(TranslationBatch $batch): BatchResult => PseudoLocalizationProvider::corruptingEvery(2)->translate($batch));

        new HtmlTranslator($provider)->translate('<h2>A</h2><p>One</p><h2>B</h2><p>Two</p>', Locale::fromString('pl'));

        $retry = $provider->batches[1] ?? self::fail('No corrective retry.');
        self::assertSame(['u2', 'u4'], array_map(static fn(TranslationUnit $unit): string => $unit->id->toString(), $retry->units));
        self::assertSame(['A', 'B'], $retry->context->headings);
        self::assertSame(['u2' => 0, 'u4' => 1], $retry->context->unitHeadings);
    }

    #[Test]
    public function it_sends_no_context_for_a_fragment_without_headings(): void
    {
        $provider = new CallbackProvider(static fn(TranslationBatch $batch): BatchResult => new PseudoLocalizationProvider()->translate($batch));

        new HtmlTranslator($provider)->translate('<p>A</p><p>B</p>', Locale::fromString('pl'));

        $batch = $provider->batches[0] ?? self::fail('No batch.');
        self::assertTrue($batch->context->isEmpty());
    }

    #[Test]
    public function it_passes_the_locale_pair_to_the_provider(): void
    {
        $provider = new CallbackProvider(static fn(TranslationBatch $batch): BatchResult => new PseudoLocalizationProvider()->translate($batch));

        new HtmlTranslator($provider)->translate('<p>A</p>', Locale::fromString('pl'), new TranslationOptions(source: Locale::fromString('en')));

        $batch = $provider->batches[0] ?? self::fail('No batch.');
        self::assertSame('en', $batch->locales->source?->toString());
        self::assertSame('pl', $batch->locales->target->toString());
    }

    #[Test]
    public function it_does_not_call_the_provider_without_units(): void
    {
        $provider = new CallbackProvider(static fn(TranslationBatch $batch): BatchResult => self::fail('The provider must not be called.'));

        $result = new HtmlTranslator($provider)->translate('<p> </p>', Locale::fromString('pl'));

        self::assertSame('<p> </p>', $result->html());
        self::assertTrue($result->isComplete());
        self::assertSame(0, $result->stats()->batches);
    }

    #[Test]
    public function it_lets_provider_exceptions_through(): void
    {
        $provider = new CallbackProvider(static fn(TranslationBatch $batch): BatchResult => throw new ProviderException('Service unavailable after 3 attempts.'));

        $this->expectException(ProviderException::class);

        new HtmlTranslator($provider)->translate('<p>A</p>', Locale::fromString('pl'));
    }

    #[Test]
    public function it_rejects_unusable_input(): void
    {
        $this->expectException(InvalidHtmlException::class);

        new HtmlTranslator(new PseudoLocalizationProvider())->translate('  ', Locale::fromString('pl'));
    }

    #[Test]
    public function it_rejects_identical_source_and_target(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HtmlTranslator(new PseudoLocalizationProvider())->translate('<p>A</p>', Locale::fromString('pl'), new TranslationOptions(source: Locale::fromString('pl')));
    }

    #[Test]
    public function it_logs_issues_as_warnings(): void
    {
        $logger = new CollectingLogger();

        new HtmlTranslator(PseudoLocalizationProvider::corruptingEvery(1, persistent: true), logger: $logger)
            ->translate('<p>Bye</p>', Locale::fromString('pl'), new TranslationOptions(maxCorrectiveRetries: 0));

        self::assertSame([['warning', "The translation of unit \"u1\" is still invalid after 0 corrective retries:\n- Translate the text; the translation must not be empty.", ['unit' => 'u1', 'type' => 'InvalidStructure']]], $logger->records);
    }

    #[Test]
    public function it_uses_the_given_skip_rules_and_attributes(): void
    {
        $translator = new HtmlTranslator(
            new PseudoLocalizationProvider(),
            skipRules: SkipRules::defaults()->withElement('x-brand'),
            translatableAttributes: TranslatableAttributes::defaults()->withoutAttribute('title'),
        );

        self::assertSame('<p title="T">[!! Ĝö <x-brand>Acme</x-brand> !!]</p>', $translator->translate('<p title="T">Go <x-brand>Acme</x-brand></p>', Locale::fromString('pl'))->html());
    }

    /**
     * Whole units always come back broken; their text-node pieces ("u1-1", …) are pseudo-localized,
     * except $failingPiece, which the provider refuses.
     */
    private function brokenUnitsWithTranslatedPieces(?string $failingPiece = null): CallbackProvider
    {
        return new CallbackProvider(static function (TranslationBatch $batch) use ($failingPiece): BatchResult {
            $translations = [];
            $failures = [];
            foreach ($batch->units as $unit) {
                $id = $unit->id->toString();
                if (!str_contains($id, '-')) {
                    $translations[$id] = 'Kliknij <g1>tutaj';
                } elseif ($id === $failingPiece) {
                    $failures[$id] = 'refused';
                } else {
                    $translations = [...$translations, ...new PseudoLocalizationProvider()->translate(new TranslationBatch($batch->locales, [$unit]))->translations];
                }
            }

            return new BatchResult($translations, $failures);
        });
    }

    private function segment(string $html): SegmentedDocument
    {
        return new Segmenter()->segment(new HtmlLoader()->load($html), SkipRules::defaults(), TranslatableAttributes::defaults());
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function issues(TranslatedDocument $result): array
    {
        return array_map(static fn(Issue $issue): array => [$issue->unitId->toString(), $issue->type->name, $issue->message], $result->issues());
    }
}
