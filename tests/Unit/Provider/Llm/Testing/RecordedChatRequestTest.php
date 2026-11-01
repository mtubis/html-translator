<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Provider\Llm\Testing;

use HtmlTranslator\Exception\ArrayArgument;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\UntrustedText;
use HtmlTranslator\Provider\Llm\Testing\RecordedChatRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordedChatRequest::class)]
#[UsesClass(ArrayArgument::class)]
#[UsesClass(InvalidArgumentException::class)]
#[UsesClass(UntrustedText::class)]
final class RecordedChatRequestTest extends TestCase
{
    #[Test]
    public function it_keeps_role_and_content_pairs(): void
    {
        $recorded = new RecordedChatRequest([['system', 'Translate.'], ['user', 'Hello']], 0.2, true, 100, 'm');

        self::assertSame([['system', 'Translate.'], ['user', 'Hello']], $recorded->messages);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, string}>
     */
    public static function wrongMessages(): iterable
    {
        yield 'not a list' => [['first' => ['user', 'Hello']], 'messages must be a list, got key "first".'];
        yield 'message that is not a pair' => [['user'], 'messages[0] must be an array, got string.'];
        yield 'content that is not a string' => [[['user', 42]], 'messages[0][1] must be a string, got int.'];
        yield 'too many elements' => [[['user', 'Hello', 'extra']], 'messages[0] must be a [role, content] pair, got 3 elements.'];
    }

    /**
     * Adapter tests build the messages from decoded wire JSON, which PHPStan cannot check (#78).
     *
     * @param array<array-key, mixed> $messages
     */
    #[Test]
    #[DataProvider('wrongMessages')]
    public function it_rejects_messages_that_are_not_role_and_content_pairs(array $messages, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        // @phpstan-ignore argument.type (deliberately wrong input: the runtime check is what is under test)
        new RecordedChatRequest($messages, null, false, null, null);
    }
}
