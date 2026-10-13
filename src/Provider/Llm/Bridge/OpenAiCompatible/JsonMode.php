<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible;

/**
 * How OpenAiCompatibleChatModel asks for JSON (response_format) when a request sets ChatRequest::$jsonResponse.
 * Servers differ: some only know json_object, LM Studio only json_schema (docs/providers.md).
 */
enum JsonMode: string
{
    /** No response_format; the prompt alone asks for JSON. */
    case None = 'none';

    /** {"type": "json_object"}: valid JSON, any shape. */
    case JsonObject = 'json_object';

    /** The request's schema as {"type": "json_schema"} (strict); json_object when the request has no schema. */
    case JsonSchema = 'json_schema';

    /** As JsonSchema, but no response_format without a schema, for servers that reject json_object. */
    case JsonSchemaOnly = 'json_schema_only';
}
