<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit;

use HtmlTranslator\Batching\Batcher;
use HtmlTranslator\Batching\CharacterTokenEstimator;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Domain\LocalePair;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Domain\UnitText;
use HtmlTranslator\Domain\UnitTextToken;
use HtmlTranslator\Exception\ArrayArgument;
use HtmlTranslator\Exception\UntrustedText;
use HtmlTranslator\FallbackStrategy;
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
use HtmlTranslator\Profile\TermMatcher;
use HtmlTranslator\Profile\TranslationProfile;
use HtmlTranslator\Provider\BatchContext;
use HtmlTranslator\Provider\BatchResult;
use HtmlTranslator\Provider\Llm\ChatMessage;
use HtmlTranslator\Provider\Llm\ChatRequest;
use HtmlTranslator\Provider\Llm\ChatResponse;
use HtmlTranslator\Provider\Llm\JsonSchema;
use HtmlTranslator\Provider\Llm\LlmTranslationProvider;
use HtmlTranslator\Provider\Llm\PromptBuilder;
use HtmlTranslator\Provider\Llm\ResponseParser;
use HtmlTranslator\Provider\Llm\Testing\FakeChatModel;
use HtmlTranslator\Provider\Testing\PseudoLocalizationProvider;
use HtmlTranslator\Provider\TokenUsage;
use HtmlTranslator\Provider\TranslationBatch;
use HtmlTranslator\Tests\Support\PseudoLocalizingChatModel;
use HtmlTranslator\TranslatedDocument;
use HtmlTranslator\TranslationOptions;
use HtmlTranslator\TranslationStats;
use HtmlTranslator\Validation\StructureValidator;
use HtmlTranslator\Validation\TagNesting;
use HtmlTranslator\Validation\TermChecker;
use HtmlTranslator\Validation\ValidationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * HtmlTranslator with an LLM provider whose model refuses one sentence (ADR-0019, #79): the refused unit is isolated,
 * every other unit of its batch is translated.
 */
#[CoversClass(HtmlTranslator::class)]
#[CoversClass(LlmTranslationProvider::class)]
#[UsesClass(Batcher::class)]
#[UsesClass(CharacterTokenEstimator::class)]
#[UsesClass(Locale::class)]
#[UsesClass(LocalePair::class)]
#[UsesClass(TranslationUnit::class)]
#[UsesClass(UnitId::class)]
#[UsesClass(UnitText::class)]
#[UsesClass(UnitTextToken::class)]
#[UsesClass(ArrayArgument::class)]
#[UsesClass(UntrustedText::class)]
#[UsesClass(EncodedRun::class)]
#[UsesClass(HtmlLoader::class)]
#[UsesClass(InlineEncoder::class)]
#[UsesClass(LoadedDocument::class)]
#[UsesClass(Reassembler::class)]
#[UsesClass(RunAnchor::class)]
#[UsesClass(SegmentationContext::class)]
#[UsesClass(SegmentedDocument::class)]
#[UsesClass(Segmenter::class)]
#[UsesClass(SkipRules::class)]
#[UsesClass(TranslatableAttributes::class)]
#[UsesClass(Issue::class)]
#[UsesClass(Glossary::class)]
#[UsesClass(TermMatcher::class)]
#[UsesClass(TranslationProfile::class)]
#[UsesClass(BatchContext::class)]
#[UsesClass(BatchResult::class)]
#[UsesClass(ChatMessage::class)]
#[UsesClass(ChatRequest::class)]
#[UsesClass(ChatResponse::class)]
#[UsesClass(JsonSchema::class)]
#[UsesClass(PromptBuilder::class)]
#[UsesClass(ResponseParser::class)]
#[UsesClass(FakeChatModel::class)]
#[UsesClass(PseudoLocalizationProvider::class)]
#[UsesClass(TokenUsage::class)]
#[UsesClass(TranslationBatch::class)]
#[UsesClass(TranslatedDocument::class)]
#[UsesClass(TranslationOptions::class)]
#[UsesClass(TranslationStats::class)]
#[UsesClass(StructureValidator::class)]
#[UsesClass(TagNesting::class)]
#[UsesClass(TermChecker::class)]
#[UsesClass(ValidationResult::class)]
final class HtmlTranslatorRefusalTest extends TestCase
{
    private const string HTML = '<p>One</p><p>Two</p><p>Three</p><p>Four</p><p>Five</p><p>Six</p><p>Seven</p><p>Eight</p>';

    #[Test]
    public function it_translates_every_unit_but_the_refused_one_and_reports_one_issue(): void
    {
        $model = PseudoLocalizingChatModel::refusing(static fn(array $ids): bool => \in_array('u5', $ids, true));

        $result = new HtmlTranslator(new LlmTranslationProvider($model))->translate(self::HTML, Locale::fromString('pl'), new TranslationOptions(maxCorrectiveRetries: 0));

        self::assertSame(
            '<p>[!! Öñé !!]</p><p>[!! Ţŵö !!]</p><p>[!! Ţĥŕéé !!]</p><p>[!! Ƒöüŕ !!]</p><p>Five</p><p>[!! Šíẋ !!]</p><p>[!! Šéṽéñ !!]</p><p>[!! Éíĝĥţ !!]</p>',
            $result->html(),
        );
        self::assertFalse($result->isComplete());
        self::assertCount(1, $result->issues());
        self::assertSame('u5', $result->issues()[0]->unitId->toString());
        self::assertSame(IssueType::ProviderFailure, $result->issues()[0]->type);
        self::assertStringContainsString('refused', $result->issues()[0]->message);
        self::assertSame(1, $result->stats()->batches, 'The parts sent to isolate the refusal are the provider\'s calls, not batches.');
        self::assertSame(22, $result->stats()->inputTokens, 'Every call made to isolate the refusal counts.');
        self::assertCount(7, $model->requests);
    }

    #[Test]
    public function it_does_not_retry_an_isolated_refusal(): void
    {
        $model = PseudoLocalizingChatModel::refusing(static fn(array $ids): bool => \in_array('u5', $ids, true));

        $result = new HtmlTranslator(new LlmTranslationProvider($model))->translate(self::HTML, Locale::fromString('pl'), new TranslationOptions(fallback: FallbackStrategy::KeepOriginal, maxCorrectiveRetries: 2));

        self::assertCount(1, $result->issues());
        self::assertCount(7, $model->requests, 'A permanent failure is not sent again in a corrective retry.');
    }
}
