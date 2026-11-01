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
use HtmlTranslator\Exception\ArrayArgument;
use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Exception\UntrustedText;
use HtmlTranslator\Profile\Glossary;
use HtmlTranslator\Profile\TermMatcher;
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
use HtmlTranslator\Provider\Testing\PseudoLocalizationProvider;
use HtmlTranslator\Provider\TokenUsage;
use HtmlTranslator\Provider\TranslationBatch;
use HtmlTranslator\Tests\Support\PseudoLocalizingChatModel;
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
#[UsesClass(TermMatcher::class)]
#[UsesClass(TranslationProfile::class)]
#[UsesClass(BatchContext::class)]
#[UsesClass(JsonSchema::class)]
#[UsesClass(TokenUsage::class)]
#[UsesClass(UntrustedText::class)]
#[UsesClass(ArrayArgument::class)]
#[UsesClass(PseudoLocalizationProvider::class)]
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

        $result = new LlmTranslationProvider($model, isolateRefusals: false)->translate($this->batch());

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

        $result = new LlmTranslationProvider($model, isolateRefusals: false)->translate($this->batch());

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

        $result = new LlmTranslationProvider($model, isolateRefusals: false)->translate($this->batch());

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

        $result = new LlmTranslationProvider($model, isolateRefusals: false)->translate($this->batch());

        self::assertEquals(new TokenUsage(120, 8), $result->usage);
    }

    #[Test]
    public function it_sums_the_token_usage_of_a_refusal_on_the_second_call(): void
    {
        $model = FakeChatModel::withResponses(
            $this->response('not JSON', inputTokens: 120, outputTokens: 5),
            $this->response("I can't help with that.", FinishReason::Refusal, 120, 8),
        );

        $result = new LlmTranslationProvider($model, isolateRefusals: false)->translate($this->batch());

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

    #[Test]
    public function it_reports_the_tokens_of_a_cut_off_answer_it_cannot_parse(): void
    {
        $model = FakeChatModel::withResponses($this->response('{"units": [{"id": "u1", "text": "Jed', FinishReason::Length, 1200, 16000));

        self::assertEquals(new TokenUsage(1200, 16000), $this->failure($model)->usage);
    }

    #[Test]
    public function it_reports_the_tokens_of_both_answers_it_cannot_parse(): void
    {
        $model = FakeChatModel::withResponses($this->response('not JSON', inputTokens: 100, outputTokens: 5), $this->response('still not JSON', inputTokens: 100, outputTokens: 6));

        self::assertEquals(new TokenUsage(200, 11), $this->failure($model)->usage);
    }

    #[Test]
    public function it_adds_the_tokens_of_the_first_answer_to_an_error_of_the_second_call(): void
    {
        $error = ProviderException::fromHttpResponse(429, 'Rate limited.', 'rate_limit_exceeded', usage: new TokenUsage(50, null));
        $model = FakeChatModel::withResponses($this->response('not JSON', inputTokens: 100, outputTokens: 5), $error);

        $exception = $this->failure($model);

        self::assertEquals(new TokenUsage(150, 5), $exception->usage);
        self::assertSame('Rate limited.', $exception->getMessage());
        self::assertSame(429, $exception->statusCode);
        self::assertSame('rate_limit_exceeded', $exception->vendorCode);
    }

    #[Test]
    public function it_passes_an_error_of_the_second_call_through_unchanged_when_the_first_answer_reported_no_tokens(): void
    {
        $error = ProviderException::fromHttpResponse(429, 'Rate limited.', usage: new TokenUsage(50, null));
        $model = FakeChatModel::withResponses($this->response('not JSON'), $error);

        self::assertSame($error, $this->failure($model));
    }

    #[Test]
    public function it_isolates_the_refused_unit_of_a_batch(): void
    {
        $model = PseudoLocalizingChatModel::refusing(static fn(array $ids): bool => \in_array('u5', $ids, true));

        $result = new LlmTranslationProvider($model)->translate($this->batchOf(8));

        self::assertSame(['u1', 'u2', 'u3', 'u4', 'u6', 'u7', 'u8'], $this->sortedIds($result->translations));
        self::assertSame(['u5'], array_keys($result->permanentFailures));
        self::assertStringContainsString('refused', $result->permanentFailures['u5'] ?? '');
        self::assertSame([], $result->failures);
        self::assertCount(1 + 6, $model->requests, 'The first call and at most 2 × ⌈log2 8⌉ = 6 more.');
    }

    #[Test]
    public function it_stays_within_the_call_bound_when_the_model_refuses_every_unit(): void
    {
        $model = PseudoLocalizingChatModel::refusing(static fn(array $ids): bool => true);

        $result = new LlmTranslationProvider($model)->translate($this->batchOf(8));

        self::assertSame([], $result->translations);
        self::assertSame(['u1', 'u2', 'u3', 'u4', 'u5', 'u6', 'u7', 'u8'], $this->sortedIds($result->permanentFailures));
        self::assertCount(1 + 6, $model->requests);
    }

    /**
     * Breadth first, so the budget is shared by every refused part instead of being spent on the first one.
     */
    #[Test]
    public function it_shares_the_call_budget_between_several_refused_units(): void
    {
        $model = PseudoLocalizingChatModel::refusing(static fn(array $ids): bool => [] !== array_intersect(['u2', 'u7'], $ids));

        $result = new LlmTranslationProvider($model)->translate($this->batchOf(8));

        self::assertSame(['u3', 'u4', 'u5', 'u6'], $this->sortedIds($result->translations));
        self::assertSame(['u1', 'u2', 'u7', 'u8'], $this->sortedIds($result->permanentFailures));
        self::assertStringContainsString('limit of calls', $result->permanentFailures['u1'] ?? '');
        self::assertStringContainsString('limit of calls', $result->permanentFailures['u8'] ?? '', 'u7–u8 is still refused when the budget is spent.');
        self::assertCount(1 + 6, $model->requests);
    }

    #[Test]
    public function it_does_not_send_a_refused_single_unit_again(): void
    {
        $model = PseudoLocalizingChatModel::refusing(static fn(array $ids): bool => true);

        $result = new LlmTranslationProvider($model)->translate($this->batchOf(1));

        self::assertSame(['u1'], array_keys($result->permanentFailures));
        self::assertCount(1, $model->requests);
    }

    #[Test]
    public function it_fails_the_whole_refused_batch_when_isolation_is_turned_off(): void
    {
        $model = PseudoLocalizingChatModel::refusing(static fn(array $ids): bool => \in_array('u5', $ids, true));

        $result = new LlmTranslationProvider($model, isolateRefusals: false)->translate($this->batchOf(8));

        self::assertSame([], $result->translations);
        self::assertCount(8, $result->permanentFailures);
        self::assertCount(1, $model->requests);
    }

    #[Test]
    public function it_sums_the_tokens_of_every_call_it_made_to_isolate_a_refusal(): void
    {
        $model = PseudoLocalizingChatModel::refusing(static fn(array $ids): bool => \in_array('u5', $ids, true));

        $result = new LlmTranslationProvider($model)->translate($this->batchOf(8));

        // Input tokens = units per call: 8, then 4 + 4, 2 + 2, 1 + 1; one output token per call.
        self::assertEquals(new TokenUsage(22, 7), $result->usage);
    }

    #[Test]
    public function it_names_a_content_filter_in_the_reason_of_an_isolated_unit(): void
    {
        $model = PseudoLocalizingChatModel::refusing(static fn(array $ids): bool => \in_array('u2', $ids, true), FinishReason::ContentFilter);

        $result = new LlmTranslationProvider($model)->translate($this->batchOf(2));

        self::assertSame(['u1'], array_keys($result->translations));
        self::assertStringContainsString('content filter', $result->permanentFailures['u2'] ?? '');
    }

    /**
     * Parts are not asked once more, so the bound stays exact; HtmlTranslator retries their units as usual.
     */
    #[Test]
    public function it_leaves_the_units_of_a_part_it_cannot_read_to_the_corrective_retries(): void
    {
        $model = FakeChatModel::withResponses(
            $this->response('No.', FinishReason::Refusal),
            $this->response('not JSON'),
            $this->response('{"units": [{"id": "u2", "text": "Dwa"}]}'),
        );

        $result = new LlmTranslationProvider($model)->translate($this->batch());

        self::assertSame(['u2' => 'Dwa'], $result->translations);
        self::assertSame(['u1'], array_keys($result->failures));
        self::assertSame([], $result->permanentFailures);
        self::assertCount(3, $model->requests);
    }

    /**
     * Never worse than before isolation existed: a transient error (e.g. a rate limit) does not abort the document.
     */
    #[Test]
    public function it_fails_the_unresolved_units_as_refused_when_the_model_fails_while_isolating(): void
    {
        $model = FakeChatModel::withResponses(
            $this->response('No.', FinishReason::Refusal, inputTokens: 100, outputTokens: 2),
            $this->response('{"units": [{"id": "u1", "text": "Jeden"}]}', inputTokens: 50, outputTokens: 3),
            ProviderException::fromHttpResponse(429, 'Rate limited.', usage: new TokenUsage(40, null)),
        );

        $result = new LlmTranslationProvider($model)->translate($this->batch());

        self::assertSame(['u1' => 'Jeden'], $result->translations);
        self::assertSame(['u2'], array_keys($result->permanentFailures));
        self::assertStringContainsString('refused', $result->permanentFailures['u2'] ?? '');
        self::assertStringContainsString('Rate limited.', $result->permanentFailures['u2'] ?? '');
        self::assertEquals(new TokenUsage(190, 5), $result->usage);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function unevenBatches(): iterable
    {
        yield '3 units' => [3, 'u2'];
        yield '5 units' => [5, 'u3'];
        yield '20 units, early' => [20, 'u3'];
        yield '20 units, last' => [20, 'u20'];
    }

    #[Test]
    #[DataProvider('unevenBatches')]
    public function it_isolates_a_refused_unit_within_the_bound_for_any_batch_size(int $units, string $refusedId): void
    {
        $model = PseudoLocalizingChatModel::refusing(static fn(array $ids): bool => \in_array($refusedId, $ids, true));

        $result = new LlmTranslationProvider($model)->translate($this->batchOf($units));

        self::assertSame([$refusedId], array_keys($result->permanentFailures));
        self::assertCount($units - 1, $result->translations);
        self::assertLessThanOrEqual(1 + 2 * (int) ceil(log($units, 2)), \count($model->requests));
    }

    #[Test]
    public function it_sends_each_part_with_the_corrections_and_the_context_of_its_units(): void
    {
        $model = PseudoLocalizingChatModel::refusing(static fn(array $ids): bool => \in_array('u1', $ids, true));
        $units = $this->batchOf(4)->units;
        $batch = new TranslationBatch(
            LocalePair::of(Locale::fromString('en'), Locale::fromString('pl')),
            $units,
            ['u3' => new Correction('Zdanie numer trzy (stare)', 'Unit "u3" lost <g1>.')],
            context: BatchContext::forUnits('Installation guide', ['u1' => null, 'u2' => null, 'u3' => 'Requirements', 'u4' => null]),
        );

        new LlmTranslationProvider($model)->translate($batch);

        $prompts = array_map(static fn(ChatRequest $request): string => implode("\n", array_map(static fn(ChatMessage $message): string => $message->content, $request->messages)), $model->requests);
        $withU3 = array_values(array_filter($prompts, static fn(string $prompt): bool => str_contains($prompt, 'Sentence number 3')));
        $withoutU3 = array_values(array_filter($prompts, static fn(string $prompt): bool => !str_contains($prompt, 'Sentence number 3')));
        self::assertCount(2, $withU3, 'The whole batch and the half with u3.');
        foreach ($withU3 as $prompt) {
            self::assertStringContainsString('Zdanie numer trzy (stare)', $prompt);
            self::assertStringContainsString('Requirements', $prompt);
        }
        self::assertNotSame([], $withoutU3);
        foreach ($withoutU3 as $prompt) {
            self::assertStringNotContainsString('Zdanie numer trzy (stare)', $prompt);
            self::assertStringNotContainsString('Requirements', $prompt);
            self::assertStringContainsString('Installation guide', $prompt);
        }
    }

    /**
     * Calls: the batch, its halves u1–u4 and u5–u8 (both refused, queued), then u1–u2 (refused, queued) and u3–u4,
     * which throws: u3–u4 and both queued parts are unresolved, each with the reason it was refused for.
     */
    #[Test]
    public function it_fails_every_unresolved_part_with_its_own_reason_when_the_model_fails_while_isolating(): void
    {
        $calls = 0;
        $model = PseudoLocalizingChatModel::refusing(static function (array $ids) use (&$calls): bool|FinishReason {
            if (5 === ++$calls) {
                throw new ProviderException('Rate limited.', usage: new TokenUsage(3, null));
            }

            return match (true) {
                \in_array('u7', $ids, true) => FinishReason::ContentFilter,
                \in_array('u2', $ids, true) => FinishReason::Refusal,
                default => false,
            };
        });

        $result = new LlmTranslationProvider($model)->translate($this->batchOf(8));

        self::assertSame([], $result->translations);
        self::assertSame(['u1', 'u2', 'u3', 'u4', 'u5', 'u6', 'u7', 'u8'], $this->sortedIds($result->permanentFailures));
        self::assertStringContainsString('stopped: Rate limited.', $result->permanentFailures['u3'] ?? '');
        self::assertStringContainsString('content filter', $result->permanentFailures['u5'] ?? '');
        self::assertStringContainsString('refused to translate', $result->permanentFailures['u1'] ?? '');
        self::assertEquals(new TokenUsage(8 + 4 + 4 + 2 + 3, 4), $result->usage);
    }

    /**
     * @param array<string, string> $byId
     *
     * @return list<string>
     */
    private function sortedIds(array $byId): array
    {
        $ids = array_map(strval(...), array_keys($byId));
        sort($ids, \SORT_NATURAL);

        return $ids;
    }

    private function batchOf(int $units): TranslationBatch
    {
        $list = [];
        for ($number = 1; $number <= $units; ++$number) {
            $list[] = TranslationUnit::fromText(UnitId::fromString('u' . $number), UnitKind::Block, 'Sentence number ' . $number);
        }

        return new TranslationBatch(LocalePair::of(Locale::fromString('en'), Locale::fromString('pl')), $list);
    }

    private function failure(FakeChatModel $model): ProviderException
    {
        try {
            new LlmTranslationProvider($model)->translate($this->batch());
        } catch (ProviderException $exception) {
            return $exception;
        }

        self::fail('Expected a ProviderException.');
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
