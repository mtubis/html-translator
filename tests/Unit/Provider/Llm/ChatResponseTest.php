<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Provider\Llm;

use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Provider\Llm\ChatResponse;
use HtmlTranslator\Provider\Llm\FinishReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChatResponse::class)]
#[UsesClass(InvalidArgumentException::class)]
final class ChatResponseTest extends TestCase
{
    #[Test]
    public function it_holds_content_model_and_tokens(): void
    {
        $response = new ChatResponse('{"u1":"x"}', 'gpt-test', 12, 34);

        self::assertSame('{"u1":"x"}', $response->content);
        self::assertSame('gpt-test', $response->model);
        self::assertSame(12, $response->inputTokens);
        self::assertSame(34, $response->outputTokens);
        self::assertNull($response->finishReason);
    }

    #[Test]
    public function it_holds_the_finish_reason(): void
    {
        self::assertSame(FinishReason::Length, new ChatResponse('x', 'm', finishReason: FinishReason::Length)->finishReason);
    }

    #[Test]
    public function it_may_have_empty_content_and_no_token_counts(): void
    {
        $response = new ChatResponse('', 'local-model');

        self::assertSame('', $response->content);
        self::assertNull($response->inputTokens);
        self::assertNull($response->outputTokens);
    }

    #[Test]
    public function it_accepts_zero_tokens(): void
    {
        self::assertSame(0, new ChatResponse('x', 'm', 0, 0)->outputTokens);
    }

    /**
     * @return iterable<string, array{string, ?int, ?int}>
     */
    public static function invalidResponses(): iterable
    {
        yield 'blank model' => [' ', null, null];
        yield 'negative input tokens' => ['m', -1, null];
        yield 'negative output tokens' => ['m', null, -1];
    }

    #[Test]
    #[DataProvider('invalidResponses')]
    public function it_rejects_invalid_values(string $model, ?int $inputTokens, ?int $outputTokens): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ChatResponse('x', $model, $inputTokens, $outputTokens);
    }
}
