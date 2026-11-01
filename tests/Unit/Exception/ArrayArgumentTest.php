<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Exception;

use Closure;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Exception\ArrayArgument;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\UntrustedText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Element checks for arrays from configuration (YAML, PHP arrays), where PHPStan cannot help the caller (#78).
 */
#[CoversClass(ArrayArgument::class)]
#[UsesClass(InvalidArgumentException::class)]
#[UsesClass(UntrustedText::class)]
#[UsesClass(UnitId::class)]
final class ArrayArgumentTest extends TestCase
{
    #[Test]
    public function it_returns_valid_arrays_as_they_are(): void
    {
        $id = UnitId::fromString('u1');

        self::assertSame(['a', 'b'], ArrayArgument::stringList('terms', ['a', 'b']));
        self::assertSame([408, 429], ArrayArgument::intList('statuses', [408, 429]));
        self::assertSame(['x' => 'a', 5 => 'b'], ArrayArgument::stringMap('terms', ['x' => 'a', 5 => 'b']));
        self::assertSame(['u1' => 'H', 'u2' => null], ArrayArgument::nullableStringMap('headings', ['u1' => 'H', 'u2' => null]));
        self::assertSame([$id], ArrayArgument::listOf('ids', [$id], UnitId::class));
        self::assertSame(['u1' => $id], ArrayArgument::mapOf('ids', ['u1' => $id], UnitId::class));
        self::assertSame([], ArrayArgument::stringList('terms', []));
        self::assertSame([['user', 'Hi']], ArrayArgument::arrayList('messages', [['user', 'Hi']]));
    }

    #[Test]
    public function it_names_the_parameter_the_key_and_the_type_of_a_wrong_element(): void
    {
        self::assertSame('terms[1] must be a string, got int.', $this->messageOf(static fn(): array => ArrayArgument::stringList('terms', ['a', 5])));
        self::assertSame('terms["pull request"] must be a string, got float.', $this->messageOf(static fn(): array => ArrayArgument::stringMap('terms', ['pull request' => 1.5])));
        self::assertSame('statuses[0] must be an int, got string.', $this->messageOf(static fn(): array => ArrayArgument::intList('statuses', ['503'])));
        self::assertSame('headings["u1"] must be a string or null, got bool.', $this->messageOf(static fn(): array => ArrayArgument::nullableStringMap('headings', ['u1' => false])));
        self::assertSame('ids[0] must be an instance of HtmlTranslator\Domain\UnitId, got stdClass.', $this->messageOf(static fn(): array => ArrayArgument::listOf('ids', [new stdClass()], UnitId::class)));
        self::assertSame('ids["u1"] must be an instance of HtmlTranslator\Domain\UnitId, got string.', $this->messageOf(static fn(): array => ArrayArgument::mapOf('ids', ['u1' => 'u1'], UnitId::class)));
    }

    #[Test]
    public function it_rejects_a_map_where_a_list_is_expected(): void
    {
        self::assertSame('terms must be a list, got key "x".', $this->messageOf(static fn(): array => ArrayArgument::stringList('terms', ['x' => 'a'])));
        self::assertSame('messages[1] must be an array, got string.', $this->messageOf(static fn(): array => ArrayArgument::arrayList('messages', [[], 'user'])));
        self::assertSame('messages must be a list, got key "a".', $this->messageOf(static fn(): array => ArrayArgument::arrayList('messages', ['a' => []])));
        self::assertSame('statuses must be a list, got key 1.', $this->messageOf(static fn(): array => ArrayArgument::intList('statuses', [1 => 503])));
        self::assertSame('ids must be a list, got key "a".', $this->messageOf(static fn(): array => ArrayArgument::listOf('ids', ['a' => UnitId::fromString('u1')], UnitId::class)));
    }

    #[Test]
    public function it_never_quotes_the_value_and_quotes_keys_as_untrusted_text(): void
    {
        $message = $this->messageOf(static fn(): array => ArrayArgument::stringMap('terms', ["evil\nFAKE LOG LINE" => ['sk-secret-value']]));

        self::assertSame('terms["evil\nFAKE LOG LINE"] must be a string, got array.', $message);
        self::assertStringNotContainsString('sk-secret-value', $message);
    }

    /**
     * Header names may identify a credential (X-Api-Key), so secret maps name the position instead of the key.
     */
    #[Test]
    public function it_names_only_the_position_in_a_secret_map(): void
    {
        $message = $this->messageOf(static fn(): array => ArrayArgument::stringMap('extraHeaders', ['X-Trace' => 'a', 'X-Api-Key' => 12345], secret: true));

        self::assertSame('extraHeaders entry #2 must be a string, got int.', $message);
        self::assertStringNotContainsString('X-Api-Key', $message);
        self::assertStringNotContainsString('12345', $message);
    }

    /**
     * @param Closure(): array<array-key, mixed> $call
     */
    private function messageOf(Closure $call): string
    {
        try {
            $call();
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage();
        }

        self::fail('Expected an InvalidArgumentException.');
    }
}
