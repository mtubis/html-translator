<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Tools\Eval;

use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Provider\Llm\ChatMessage;
use HtmlTranslator\Provider\Llm\ChatRequest;
use HtmlTranslator\Provider\Llm\ChatResponse;
use HtmlTranslator\Provider\Llm\ChatRole;
use HtmlTranslator\Provider\Llm\Testing\FakeChatModel;
use HtmlTranslator\Tools\Eval\TokenCountingChatModel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * tools/ is not part of the measured source, hence CoversNothing.
 */
#[CoversNothing]
final class TokenCountingChatModelTest extends TestCase
{
    #[Test]
    public function it_counts_calls_and_reported_tokens(): void
    {
        $model = new TokenCountingChatModel(FakeChatModel::withResponses(
            new ChatResponse('a', 'm', 100, 10),
            new ChatResponse('b', 'm'),
            new ChatResponse('c', 'm', 50, 5),
        )->withModelId('fake:counted'));

        $response = $model->complete($this->request());
        $model->complete($this->request());
        $model->complete($this->request());

        self::assertSame('a', $response->content);
        self::assertSame('fake:counted', $model->modelId());
        self::assertSame(['calls' => 3, 'inputTokens' => 150, 'outputTokens' => 15, 'callsWithContext' => 0], $model->usage());
    }

    #[Test]
    public function it_reports_unknown_tokens_until_the_model_sends_some_and_resets(): void
    {
        $model = new TokenCountingChatModel(FakeChatModel::withResponses(new ChatResponse('a', 'm'), new ChatResponse('b', 'm', 0, 0)));

        $model->complete($this->request());
        self::assertSame(['calls' => 1, 'inputTokens' => null, 'outputTokens' => null, 'callsWithContext' => 0], $model->usage());

        $model->reset();
        $model->complete($this->request());
        self::assertSame(['calls' => 1, 'inputTokens' => 0, 'outputTokens' => 0, 'callsWithContext' => 0], $model->usage());
    }

    #[Test]
    public function it_counts_calls_that_carry_context_hints(): void
    {
        $model = new TokenCountingChatModel(FakeChatModel::withResponses(new ChatResponse('a', 'm'), new ChatResponse('b', 'm')));

        $model->complete(new ChatRequest([new ChatMessage(ChatRole::System, 'Translate.'), new ChatMessage(ChatRole::User, "{\"context\": {\"title\":\"Shop\"},\n\"units\": []}")]));
        $model->complete(new ChatRequest([new ChatMessage(ChatRole::System, 'Translate.'), new ChatMessage(ChatRole::User, '{"units": []}')]));

        self::assertSame(2, $model->usage()['calls']);
        self::assertSame(1, $model->usage()['callsWithContext']);
    }

    #[Test]
    public function it_counts_a_failed_call(): void
    {
        $model = new TokenCountingChatModel(FakeChatModel::withResponses(new ProviderException('down')));

        try {
            $model->complete($this->request());
            self::fail('Expected a ProviderException.');
        } catch (ProviderException) {
            self::assertSame(['calls' => 1, 'inputTokens' => null, 'outputTokens' => null, 'callsWithContext' => 0], $model->usage());
        }
    }

    private function request(): ChatRequest
    {
        return new ChatRequest([new ChatMessage(ChatRole::User, 'Hi')]);
    }
}
