<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Provider\Llm\Testing;

use HtmlTranslator\Exception\LogicException;
use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Provider\Llm\ChatMessage;
use HtmlTranslator\Provider\Llm\ChatRequest;
use HtmlTranslator\Provider\Llm\ChatResponse;
use HtmlTranslator\Provider\Llm\ChatRole;
use HtmlTranslator\Provider\Llm\Testing\FakeChatModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(FakeChatModel::class)]
#[UsesClass(ChatRequest::class)]
#[UsesClass(ChatMessage::class)]
#[UsesClass(ChatResponse::class)]
#[UsesClass(ProviderException::class)]
#[UsesClass(LogicException::class)]
final class FakeChatModelTest extends TestCase
{
    #[Test]
    public function it_answers_from_its_queue_in_order_and_records_requests(): void
    {
        $first = new ChatResponse('one', 'fake');
        $second = new ChatResponse('two', 'fake');
        $model = FakeChatModel::withResponses($first, $second);

        self::assertSame($first, $model->complete($this->request('a')));
        self::assertSame($second, $model->complete($this->request('b')));
        self::assertSame(['a', 'b'], array_map(static fn(ChatRequest $request): string => $request->messages[0]->content ?? '', $model->requests));
    }

    #[Test]
    public function it_throws_scripted_exceptions(): void
    {
        $failure = ProviderException::fromHttpResponse(429, 'Rate limited.');
        $model = FakeChatModel::withResponses($failure, new ChatResponse('ok', 'fake'));

        try {
            $model->complete($this->request('a'));
            self::fail('Expected exception was not thrown.');
        } catch (ProviderException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame('ok', $model->complete($this->request('b'))->content);
        self::assertCount(2, $model->requests, 'A request answered with an exception is recorded too.');
    }

    #[Test]
    public function it_fails_clearly_when_the_queue_is_empty(): void
    {
        $model = FakeChatModel::withResponses(new ChatResponse('one', 'fake'));
        $model->complete($this->request('a'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no scripted response left for request #2');

        $model->complete($this->request('b'));
    }

    #[Test]
    public function it_answers_through_a_callback(): void
    {
        $model = FakeChatModel::answering(static fn(ChatRequest $request): ChatResponse => new ChatResponse(strtoupper($request->messages[0]->content ?? ''), 'echo'));

        self::assertSame('HELLO', $model->complete($this->request('hello'))->content);
        self::assertCount(1, $model->requests);
    }

    #[Test]
    public function it_lets_exceptions_of_the_callback_through_and_records_the_request(): void
    {
        $model = FakeChatModel::answering(static fn(ChatRequest $request): ChatResponse => throw new RuntimeException('boom'));

        try {
            $model->complete($this->request('a'));
            self::fail('Expected exception was not thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        self::assertCount(1, $model->requests);
    }

    #[Test]
    public function it_has_a_configurable_model_id(): void
    {
        $model = FakeChatModel::withResponses();
        $named = $model->withModelId('fake:gpt');

        self::assertSame('fake:model', $model->modelId());
        self::assertSame('fake:gpt', $named->modelId());
    }

    private function request(string $content): ChatRequest
    {
        return new ChatRequest([new ChatMessage(ChatRole::User, $content)]);
    }
}
