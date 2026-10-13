<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm;

use HtmlTranslator\Exception\InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * The JSON schema a chat model's answer must follow (ADR-0014). Adapters with structured outputs send it to the
 * vendor; adapters without ignore it and rely on JSON mode or the prompt.
 *
 * Adapters may enforce it in strict mode (OpenAI's json_schema), so follow those rules: every property listed in
 * "required" and "additionalProperties": false on every object.
 *
 * Kept as JSON text, so empty objects stay objects and no untyped array crosses the port.
 */
final readonly class JsonSchema
{
    private const int JSON_FLAGS = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR;

    /**
     * @param string $json compact JSON of a schema whose top level is {"type": "object", …}
     */
    private function __construct(
        public string $json,
    ) {}

    /**
     * @throws InvalidArgumentException when the text is not JSON or not an object schema
     */
    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, false, 64, \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('A JSON schema must be valid JSON: ' . $exception->getMessage() . '.', 0, $exception);
        }

        // An answer must be a JSON object (ChatRequest::$jsonResponse), so the schema describes one.
        if (!$decoded instanceof stdClass || 'object' !== ($decoded->type ?? null)) {
            throw new InvalidArgumentException('A JSON schema for an answer must describe an object: {"type": "object", …}.');
        }

        return new self(json_encode($decoded, self::JSON_FLAGS));
    }
}
