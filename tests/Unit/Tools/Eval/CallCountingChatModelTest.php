<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Tools\Eval;

use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Provider\Llm\ChatMessage;
use HtmlTranslator\Provider\Llm\ChatRequest;
use HtmlTranslator\Provider\Llm\ChatResponse;
use HtmlTranslator\Provider\Llm\ChatRole;
use HtmlTranslator\Provider\Llm\Testing\FakeChatModel;
use HtmlTranslator\Tools\Eval\CallCountingChatModel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * tools/ is not part of the measured source, hence CoversNothing.
 */
#[CoversNothing]
final class CallCountingChatModelTest extends TestCase
{
    #[Test]
    public function it_counts_calls_and_passes_answers_through(): void
    {
        $model = new CallCountingChatModel(FakeChatModel::withResponses(new ChatResponse('a', 'm'), new ChatResponse('b', 'm'))->withModelId('fake:counted'));

        $response = $model->complete($this->request());
        $model->complete($this->request());

        self::assertSame('a', $response->content);
        self::assertSame('fake:counted', $model->modelId());
        self::assertSame(['calls' => 2, 'callsWithContext' => 0], $model->counts());
    }

    #[Test]
    public function it_starts_counting_again_after_a_reset(): void
    {
        $model = new CallCountingChatModel(FakeChatModel::withResponses(new ChatResponse('a', 'm'), new ChatResponse('b', 'm')));

        $model->complete($this->request());
        $model->reset();
        $model->complete($this->request());

        self::assertSame(['calls' => 1, 'callsWithContext' => 0], $model->counts());
    }

    #[Test]
    public function it_counts_calls_that_carry_context_hints(): void
    {
        $model = new CallCountingChatModel(FakeChatModel::withResponses(new ChatResponse('a', 'm'), new ChatResponse('b', 'm')));

        $model->complete(new ChatRequest([new ChatMessage(ChatRole::System, 'Translate.'), new ChatMessage(ChatRole::User, "{\"context\": {\"title\":\"Shop\"},\n\"units\": []}")]));
        $model->complete(new ChatRequest([new ChatMessage(ChatRole::System, 'Translate.'), new ChatMessage(ChatRole::User, '{"units": []}')]));

        self::assertSame(2, $model->counts()['calls']);
        self::assertSame(1, $model->counts()['callsWithContext']);
    }

    #[Test]
    public function it_counts_a_failed_call(): void
    {
        $model = new CallCountingChatModel(FakeChatModel::withResponses(new ProviderException('down')));

        try {
            $model->complete($this->request());
            self::fail('Expected a ProviderException.');
        } catch (ProviderException) {
            self::assertSame(['calls' => 1, 'callsWithContext' => 0], $model->counts());
        }
    }

    private function request(): ChatRequest
    {
        return new ChatRequest([new ChatMessage(ChatRole::User, 'Hi')]);
    }
}
