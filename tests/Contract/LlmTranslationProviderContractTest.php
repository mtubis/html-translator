<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Contract;

use HtmlTranslator\Domain\InlineTag;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Domain\LocalePair;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Domain\UnitText;
use HtmlTranslator\Domain\UnitTextToken;
use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Profile\Glossary;
use HtmlTranslator\Profile\GlossaryEntry;
use HtmlTranslator\Profile\ProfileText;
use HtmlTranslator\Profile\TranslationProfile;
use HtmlTranslator\Provider\BatchContext;
use HtmlTranslator\Provider\BatchResult;
use HtmlTranslator\Provider\Correction;
use HtmlTranslator\Provider\Llm\ChatMessage;
use HtmlTranslator\Provider\Llm\ChatRequest;
use HtmlTranslator\Provider\Llm\ChatResponse;
use HtmlTranslator\Provider\Llm\JsonSchema;
use HtmlTranslator\Provider\Llm\LlmTranslationProvider;
use HtmlTranslator\Provider\Llm\PromptBuilder;
use HtmlTranslator\Provider\Llm\ResponseParser;
use HtmlTranslator\Provider\Llm\Testing\FakeChatModel;
use HtmlTranslator\Provider\Testing\PseudoLocalizationProvider;
use HtmlTranslator\Provider\Testing\TranslationProviderContractTestCase;
use HtmlTranslator\Provider\TokenUsage;
use HtmlTranslator\Provider\TranslationBatch;
use HtmlTranslator\Provider\TranslationProviderInterface;
use HtmlTranslator\Tests\Support\PseudoLocalizingChatModel;
use HtmlTranslator\TranslationOptions;
use HtmlTranslator\Validation\StructureValidator;
use HtmlTranslator\Validation\TagNesting;
use HtmlTranslator\Validation\ValidationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * The LLM provider on a chat model that answers like a well-behaved LLM (PseudoLocalizingChatModel).
 */
#[CoversClass(LlmTranslationProvider::class)]
#[UsesClass(TranslationProviderContractTestCase::class)]
#[UsesClass(TranslationOptions::class)]
#[UsesClass(InlineTag::class)]
#[UsesClass(Locale::class)]
#[UsesClass(LocalePair::class)]
#[UsesClass(TranslationUnit::class)]
#[UsesClass(UnitId::class)]
#[UsesClass(UnitText::class)]
#[UsesClass(UnitTextToken::class)]
#[UsesClass(BatchResult::class)]
#[UsesClass(Correction::class)]
#[UsesClass(ChatMessage::class)]
#[UsesClass(ChatRequest::class)]
#[UsesClass(ChatResponse::class)]
#[UsesClass(PromptBuilder::class)]
#[UsesClass(ResponseParser::class)]
#[UsesClass(FakeChatModel::class)]
#[UsesClass(PseudoLocalizationProvider::class)]
#[UsesClass(TranslationBatch::class)]
#[UsesClass(StructureValidator::class)]
#[UsesClass(TagNesting::class)]
#[UsesClass(ValidationResult::class)]
#[UsesClass(Glossary::class)]
#[UsesClass(TranslationProfile::class)]
#[UsesClass(GlossaryEntry::class)]
#[UsesClass(ProfileText::class)]
#[UsesClass(BatchContext::class)]
#[UsesClass(JsonSchema::class)]
#[UsesClass(TokenUsage::class)]
final class LlmTranslationProviderContractTest extends TranslationProviderContractTestCase
{
    protected function provider(): TranslationProviderInterface
    {
        return new LlmTranslationProvider(PseudoLocalizingChatModel::create());
    }

    protected function unavailableProvider(): TranslationProviderInterface
    {
        return new LlmTranslationProvider(FakeChatModel::withResponses(new ProviderException('The service is unavailable.')));
    }

    protected function providerWithUndecodableAnswers(): TranslationProviderInterface
    {
        $garbage = new ChatResponse('Sorry, something went wrong.', PseudoLocalizingChatModel::MODEL);

        return new LlmTranslationProvider(FakeChatModel::withResponses($garbage, $garbage));
    }
}
