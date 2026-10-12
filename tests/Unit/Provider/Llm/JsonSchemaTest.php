<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Provider\Llm;

use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Provider\Llm\JsonSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonSchema::class)]
#[UsesClass(InvalidArgumentException::class)]
final class JsonSchemaTest extends TestCase
{
    #[Test]
    public function it_keeps_a_compact_copy_of_an_object_schema(): void
    {
        $schema = JsonSchema::fromJson("{\n  \"type\": \"object\",\n  \"properties\": {\"name\": {\"type\": \"string\", \"description\": \"Zażółć /x\"}},\n  \"required\": [\"name\"],\n  \"additionalProperties\": false\n}");

        self::assertSame('{"type":"object","properties":{"name":{"type":"string","description":"Zażółć /x"}},"required":["name"],"additionalProperties":false}', $schema->json);
    }

    #[Test]
    public function it_keeps_empty_objects_as_objects(): void
    {
        self::assertSame('{"type":"object","properties":{}}', JsonSchema::fromJson('{"type": "object", "properties": {}}')->json);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSchemas(): iterable
    {
        yield 'not JSON' => ['{"type": "object"'];
        yield 'empty' => [''];
        yield 'a list' => ['[{"type": "object"}]'];
        yield 'a string' => ['"object"'];
        yield 'not an object schema' => ['{"type": "array", "items": {"type": "string"}}'];
        yield 'no type' => ['{"properties": {}}'];
    }

    #[Test]
    #[DataProvider('invalidSchemas')]
    public function it_rejects_what_is_not_a_json_object_schema(string $json): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonSchema::fromJson($json);
    }
}
