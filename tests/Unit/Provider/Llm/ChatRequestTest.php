<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Provider\Llm;

use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Provider\Llm\ChatMessage;
use HtmlTranslator\Provider\Llm\ChatRequest;
use HtmlTranslator\Provider\Llm\ChatRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChatRequest::class)]
#[UsesClass(ChatMessage::class)]
#[UsesClass(InvalidArgumentException::class)]
final class ChatRequestTest extends TestCase
{
    #[Test]
    public function it_holds_messages_and_options(): void
    {
        $messages = [new ChatMessage(ChatRole::System, 'Translate.'), new ChatMessage(ChatRole::User, '[{"id":"u1"}]')];

        $request = new ChatRequest($messages, temperature: 0.2, jsonResponse: true, maxOutputTokens: 1024);

        self::assertSame($messages, $request->messages);
        self::assertSame(0.2, $request->temperature);
        self::assertTrue($request->jsonResponse);
        self::assertSame(1024, $request->maxOutputTokens);
        self::assertSame(ChatRole::User, $messages[1]->role);
        self::assertSame('[{"id":"u1"}]', $messages[1]->content);
    }

    #[Test]
    public function it_has_neutral_defaults(): void
    {
        $request = new ChatRequest([new ChatMessage(ChatRole::User, 'Hi')]);

        self::assertNull($request->temperature);
        self::assertFalse($request->jsonResponse);
        self::assertNull($request->maxOutputTokens);
    }

    #[Test]
    public function it_rejects_an_empty_conversation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ChatRequest([]);
    }

    /**
     * @return iterable<string, array{list<ChatMessage>}>
     */
    public static function invalidConversations(): iterable
    {
        yield 'system message only' => [[new ChatMessage(ChatRole::System, 'Rules')]];
        yield 'system message not first' => [[new ChatMessage(ChatRole::User, 'Hi'), new ChatMessage(ChatRole::System, 'Rules')]];
        yield 'two system messages' => [[new ChatMessage(ChatRole::System, 'A'), new ChatMessage(ChatRole::System, 'B'), new ChatMessage(ChatRole::User, 'Hi')]];
    }

    /**
     * @param list<ChatMessage> $messages
     */
    #[Test]
    #[DataProvider('invalidConversations')]
    public function it_rejects_misplaced_system_messages(array $messages): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ChatRequest($messages);
    }

    #[Test]
    public function it_accepts_a_system_message_first(): void
    {
        $request = new ChatRequest([new ChatMessage(ChatRole::System, 'Rules'), new ChatMessage(ChatRole::Assistant, 'Ok')]);

        self::assertCount(2, $request->messages);
    }

    #[Test]
    public function it_rejects_messages_that_are_not_a_list(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('list');

        // @phpstan-ignore argument.type (deliberately wrong input: the runtime check is what is under test)
        new ChatRequest([1 => new ChatMessage(ChatRole::User, 'Hi')]);
    }

    #[Test]
    public function it_rejects_items_that_are_not_messages(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('#1');

        // @phpstan-ignore argument.type (deliberately wrong input: the runtime check is what is under test)
        new ChatRequest([new ChatMessage(ChatRole::User, 'Hi'), 'Hello']);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidTemperatures(): iterable
    {
        yield 'negative' => [-0.1];
        yield 'above two' => [2.01];
        yield 'not a number' => [\NAN];
        yield 'infinite' => [\INF];
    }

    #[Test]
    #[DataProvider('invalidTemperatures')]
    public function it_rejects_temperatures_outside_zero_to_two(float $temperature): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ChatRequest([new ChatMessage(ChatRole::User, 'Hi')], temperature: $temperature);
    }

    #[Test]
    public function it_accepts_the_temperature_bounds(): void
    {
        self::assertSame(0.0, new ChatRequest([new ChatMessage(ChatRole::User, 'Hi')], temperature: 0.0)->temperature);
        self::assertSame(2.0, new ChatRequest([new ChatMessage(ChatRole::User, 'Hi')], temperature: 2.0)->temperature);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidTokenLimits(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[Test]
    #[DataProvider('invalidTokenLimits')]
    public function it_rejects_a_non_positive_output_limit(int $limit): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ChatRequest([new ChatMessage(ChatRole::User, 'Hi')], maxOutputTokens: $limit);
    }

    #[Test]
    public function it_accepts_an_output_limit_of_one(): void
    {
        self::assertSame(1, new ChatRequest([new ChatMessage(ChatRole::User, 'Hi')], maxOutputTokens: 1)->maxOutputTokens);
    }

}
