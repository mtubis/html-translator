<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Provider\Llm;

use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Domain\LocalePair;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Domain\UnitKind;
use HtmlTranslator\Domain\UnitText;
use HtmlTranslator\Domain\UnitTextToken;
use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Profile\Glossary;
use HtmlTranslator\Profile\TranslationProfile;
use HtmlTranslator\Provider\BatchContext;
use HtmlTranslator\Provider\BatchResult;
use HtmlTranslator\Provider\Correction;
use HtmlTranslator\Provider\Llm\ChatMessage;
use HtmlTranslator\Provider\Llm\ChatRequest;
use HtmlTranslator\Provider\Llm\ChatResponse;
use HtmlTranslator\Provider\Llm\FinishReason;
use HtmlTranslator\Provider\Llm\JsonSchema;
use HtmlTranslator\Provider\Llm\LlmTranslationProvider;
use HtmlTranslator\Provider\Llm\PromptBuilder;
use HtmlTranslator\Provider\Llm\ResponseParser;
use HtmlTranslator\Provider\Llm\Testing\FakeChatModel;
use HtmlTranslator\Provider\TokenUsage;
use HtmlTranslator\Provider\TranslationBatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LlmTranslationProvider::class)]
#[UsesClass(PromptBuilder::class)]
#[UsesClass(ResponseParser::class)]
#[UsesClass(FakeChatModel::class)]
#[UsesClass(ChatRequest::class)]
#[UsesClass(ChatMessage::class)]
#[UsesClass(ChatResponse::class)]
#[UsesClass(BatchResult::class)]
#[UsesClass(TranslationBatch::class)]
#[UsesClass(Correction::class)]
#[UsesClass(ProviderException::class)]
#[UsesClass(Locale::class)]
#[UsesClass(LocalePair::class)]
#[UsesClass(TranslationUnit::class)]
#[UsesClass(UnitId::class)]
#[UsesClass(UnitText::class)]
#[UsesClass(UnitTextToken::class)]
#[UsesClass(Glossary::class)]
#[UsesClass(TranslationProfile::class)]
#[UsesClass(BatchContext::class)]
#[UsesClass(JsonSchema::class)]
#[UsesClass(TokenUsage::class)]
final class LlmTranslationProviderTest extends TestCase
{
    private const string ANSWER = '{"units": [{"id": "u1", "text": "Jeden"}, {"id": "u2", "text": "Dwa"}]}';

    #[Test]
    public function its_cache_id_names_the_model_and_the_prompt_version(): void
    {
        $provider = new LlmTranslationProvider(FakeChatModel::withResponses()->withModelId('openai:gpt-4o-mini'));

        self::assertSame('llm:openai:gpt-4o-mini:' . PromptBuilder::VERSION, $provider->cacheId());
    }

    #[Test]
    public function it_sends_the_prompt_of_the_batch_and_returns_the_parsed_answer(): void
    {
        $model = FakeChatModel::withResponses($this->response(self::ANSWER));
        $batch = $this->batch(['u2' => Correction::forMissingUnit()]);

        $result = new LlmTranslationProvider($model)->translate($batch);

        self::assertSame(['u1' => 'Jeden', 'u2' => 'Dwa'], $result->translations);
        self::assertEquals([new PromptBuilder()->build($batch)], $model->requests);
    }

    #[Test]
    public function it_reports_unit_problems_of_the_answer_as_failures(): void
    {
        $model = FakeChatModel::withResponses($this->response('{"units": [{"id": "u1", "text": 1}, {"id": "u2", "text": "Dwa"}]}'));

        $result = new LlmTranslationProvider($model)->translate($this->batch());

        self::assertSame(['u2' => 'Dwa'], $result->translations);
        self::assertSame(['u1'], array_keys($result->failures));
    }

    #[Test]
    public function it_lets_errors_of_the_chat_model_through(): void
    {
        $error = ProviderException::fromHttpResponse(503, 'The service is unavailable.');
        $model = FakeChatModel::withResponses($error);

        try {
            new LlmTranslationProvider($model)->translate($this->batch());
            self::fail('Expected a ProviderException.');
        } catch (ProviderException $exception) {
            self::assertSame($error, $exception);
        }

        self::assertCount(1, $model->requests);
    }

    #[Test]
    public function it_lets_errors_of_the_chat_model_through_on_the_second_call(): void
    {
        $error = ProviderException::fromHttpResponse(429, 'Rate limited.', 'rate_limit_exceeded');
        $model = FakeChatModel::withResponses($this->response('not JSON'), $error);

        try {
            new LlmTranslationProvider($model)->translate($this->batch());
            self::fail('Expected a ProviderException.');
        } catch (ProviderException $exception) {
            self::assertSame($error, $exception);
        }
    }

    #[Test]
    public function it_reports_a_cut_off_second_answer_as_cut_off(): void
    {
        $model = FakeChatModel::withResponses($this->response('not JSON'), $this->response('{"units": [{"id": "u1", "te', FinishReason::Length));

        try {
            new LlmTranslationProvider($model)->translate($this->batch());
            self::fail('Expected a ProviderException.');
        } catch (ProviderException $exception) {
            self::assertStringContainsString('cut off', $exception->getMessage());
            self::assertStringContainsString('Model: fake:model.', $exception->getMessage());
        }

        self::assertCount(2, $model->requests);
    }

    #[Test]
    public function it_accepts_a_refusal_on_the_second_call(): void
    {
        $model = FakeChatModel::withResponses($this->response('not JSON'), $this->response('I cannot help with that.', FinishReason::Refusal));

        $result = new LlmTranslationProvider($model)->translate($this->batch());

        self::assertSame(['u1', 'u2'], array_keys($result->permanentFailures));
    }

    #[Test]
    public function it_asks_once_more_when_the_answer_cannot_be_parsed(): void
    {
        $model = FakeChatModel::withResponses($this->response('Sorry, here you go: nothing'), $this->response(self::ANSWER));
        $batch = $this->batch();

        $result = new LlmTranslationProvider($model)->translate($batch);

        self::assertSame(['u1' => 'Jeden', 'u2' => 'Dwa'], $result->translations);
        self::assertCount(2, $model->requests);
        self::assertEquals($model->requests[0], $model->requests[1]);
    }

    #[Test]
    public function it_gives_up_with_the_model_id_after_a_second_unusable_answer(): void
    {
        $model = FakeChatModel::withResponses($this->response('not JSON'), $this->response('still not JSON'))->withModelId('acme:model-7');

        try {
            new LlmTranslationProvider($model)->translate($this->batch());
            self::fail('Expected a ProviderException.');
        } catch (ProviderException $exception) {
            self::assertStringContainsString('acme:model-7', $exception->getMessage());
            self::assertSame(0, $exception->getCode());
            self::assertInstanceOf(ProviderException::class, $exception->getPrevious());
            self::assertStringContainsString('no complete JSON', $exception->getPrevious()->getMessage());
            self::assertStringNotContainsString('still not JSON', $exception->getMessage());
        }

        self::assertCount(2, $model->requests);
    }

    #[Test]
    public function it_does_not_ask_again_when_the_answer_was_cut_off(): void
    {
        $model = FakeChatModel::withResponses($this->response('{"units": [{"id": "u1", "text": "Jed', FinishReason::Length));

        try {
            new LlmTranslationProvider($model)->translate($this->batch());
            self::fail('Expected a ProviderException.');
        } catch (ProviderException $exception) {
            self::assertStringContainsString('cut off', $exception->getMessage());
            self::assertStringContainsString('fake:model', $exception->getMessage());
        }

        self::assertCount(1, $model->requests);
    }

    /**
     * @return iterable<string, array{FinishReason, string}>
     */
    public static function refusals(): iterable
    {
        yield 'content filter' => [FinishReason::ContentFilter, 'content filter'];
        yield 'refusal' => [FinishReason::Refusal, 'refused'];
    }

    #[Test]
    #[DataProvider('refusals')]
    public function it_reports_a_refused_batch_as_permanent_failures(FinishReason $reason, string $explanation): void
    {
        // Whatever came with the refusal is not used, even when it parses.
        $model = FakeChatModel::withResponses($this->response(self::ANSWER, $reason));

        $result = new LlmTranslationProvider($model)->translate($this->batch());

        self::assertSame([], $result->translations);
        self::assertSame([], $result->failures);
        self::assertSame(['u1', 'u2'], array_keys($result->permanentFailures));
        self::assertStringContainsString($explanation, $result->permanentFailures['u1'] ?? '');
        self::assertCount(1, $model->requests);
    }

    #[Test]
    public function it_reports_a_refusal_without_json_as_permanent_failures(): void
    {
        $model = FakeChatModel::withResponses($this->response("I can't help with that.", FinishReason::Refusal));

        $result = new LlmTranslationProvider($model)->translate($this->batch());

        self::assertSame(['u1', 'u2'], array_keys($result->permanentFailures));
    }

    #[Test]
    public function it_reports_the_token_usage_of_the_call(): void
    {
        $model = FakeChatModel::withResponses($this->response(self::ANSWER, inputTokens: 120, outputTokens: 30));

        $result = new LlmTranslationProvider($model)->translate($this->batch());

        self::assertEquals(new TokenUsage(120, 30), $result->usage);
    }

    #[Test]
    public function it_sums_the_token_usage_of_both_calls_when_it_asks_once_more(): void
    {
        $model = FakeChatModel::withResponses(
            $this->response('not JSON', inputTokens: 120, outputTokens: 5),
            $this->response(self::ANSWER, inputTokens: 120, outputTokens: 30),
        );

        $result = new LlmTranslationProvider($model)->translate($this->batch());

        self::assertEquals(new TokenUsage(240, 35), $result->usage);
    }

    #[Test]
    public function it_reports_the_token_usage_of_a_refused_batch(): void
    {
        $model = FakeChatModel::withResponses($this->response("I can't help with that.", FinishReason::Refusal, inputTokens: 120, outputTokens: 8));

        $result = new LlmTranslationProvider($model)->translate($this->batch());

        self::assertEquals(new TokenUsage(120, 8), $result->usage);
    }

    #[Test]
    public function it_sums_the_token_usage_of_a_refusal_on_the_second_call(): void
    {
        $model = FakeChatModel::withResponses(
            $this->response('not JSON', inputTokens: 120, outputTokens: 5),
            $this->response("I can't help with that.", FinishReason::Refusal, 120, 8),
        );

        $result = new LlmTranslationProvider($model)->translate($this->batch());

        self::assertSame(['u1', 'u2'], array_keys($result->permanentFailures));
        self::assertEquals(new TokenUsage(240, 13), $result->usage);
    }

    #[Test]
    public function it_reports_no_token_usage_when_the_model_reports_none(): void
    {
        $model = FakeChatModel::withResponses($this->response(self::ANSWER));

        self::assertNull(new LlmTranslationProvider($model)->translate($this->batch())->usage);
    }

    #[Test]
    public function it_keeps_the_token_counts_a_model_reports_on_only_one_of_two_calls(): void
    {
        $model = FakeChatModel::withResponses($this->response('not JSON'), $this->response(self::ANSWER, inputTokens: 120));

        self::assertEquals(new TokenUsage(120, null), new LlmTranslationProvider($model)->translate($this->batch())->usage);
    }

    private function response(string $content, FinishReason $finishReason = FinishReason::Stop, ?int $inputTokens = null, ?int $outputTokens = null): ChatResponse
    {
        return new ChatResponse($content, 'fake:model', $inputTokens, $outputTokens, $finishReason);
    }

    /**
     * @param array<string, Correction> $corrections
     */
    private function batch(array $corrections = []): TranslationBatch
    {
        $units = array_map(
            static fn(string $id): TranslationUnit => TranslationUnit::fromText(UnitId::fromString($id), UnitKind::Block, 'Text of ' . $id),
            ['u1', 'u2'],
        );

        return new TranslationBatch(LocalePair::of(Locale::fromString('en'), Locale::fromString('pl')), $units, $corrections);
    }
}
