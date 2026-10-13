<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Integration;

use HtmlTranslator\Domain\Locale;
use HtmlTranslator\FallbackStrategy;
use HtmlTranslator\HtmlTranslator;
use HtmlTranslator\Provider\Llm\ChatResponse;
use HtmlTranslator\Provider\Llm\FinishReason;
use HtmlTranslator\Provider\Llm\LlmTranslationProvider;
use HtmlTranslator\Provider\Llm\Testing\FakeChatModel;
use HtmlTranslator\TranslationOptions;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End to end without network: the token counts every chat call reports end up summed in TranslationStats.
 */
#[CoversNothing]
final class LlmTokenUsageTest extends TestCase
{
    #[Test]
    public function it_sums_the_tokens_of_parse_retries_corrective_retries_and_the_text_node_fallback(): void
    {
        $broken = '{"units": [{"id": "u1", "text": "Najpierw przeczytaj <g1>poradnik."}]}';
        $model = FakeChatModel::withResponses(
            $this->response('not JSON', 100, 3),
            $this->response($broken, 100, 20),
            $this->response($broken, 150, 20),
            $this->response('{"units": [{"id": "u1-1", "text": "Przeczytaj"}, {"id": "u1-2", "text": "poradnik"}, {"id": "u1-3", "text": "najpierw."}]}', 200, 30),
        );

        $result = new HtmlTranslator(new LlmTranslationProvider($model))->translate(
            '<p>Read the <a href="/guide">guide</a> first.</p>',
            Locale::fromString('pl'),
            new TranslationOptions(fallback: FallbackStrategy::TextNodesOnly, maxCorrectiveRetries: 1),
        );

        self::assertTrue($result->isComplete());
        self::assertCount(4, $model->requests);
        self::assertSame(550, $result->stats()->inputTokens);
        self::assertSame(73, $result->stats()->outputTokens);
    }

    #[Test]
    public function it_leaves_the_token_counts_null_when_the_model_reports_none(): void
    {
        $model = FakeChatModel::withResponses(new ChatResponse('{"units": [{"id": "u1", "text": "Cześć"}]}', 'fake:model', finishReason: FinishReason::Stop));

        $result = new HtmlTranslator(new LlmTranslationProvider($model))->translate('<p>Hello</p>', Locale::fromString('pl'));

        self::assertNull($result->stats()->inputTokens);
        self::assertNull($result->stats()->outputTokens);
    }

    private function response(string $content, int $inputTokens, int $outputTokens): ChatResponse
    {
        return new ChatResponse($content, 'fake:model', $inputTokens, $outputTokens, FinishReason::Stop);
    }
}
