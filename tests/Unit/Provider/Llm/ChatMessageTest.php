<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Provider\Llm;

use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Provider\Llm\ChatMessage;
use HtmlTranslator\Provider\Llm\ChatRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChatMessage::class)]
#[UsesClass(InvalidArgumentException::class)]
final class ChatMessageTest extends TestCase
{
    #[Test]
    public function it_holds_role_and_content(): void
    {
        $message = new ChatMessage(ChatRole::Assistant, 'Cześć');

        self::assertSame(ChatRole::Assistant, $message->role);
        self::assertSame('Cześć', $message->content);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankContents(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => [" \n\t"];
    }

    #[Test]
    #[DataProvider('blankContents')]
    public function it_rejects_blank_content(string $content): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ChatMessage(ChatRole::User, $content);
    }
}
