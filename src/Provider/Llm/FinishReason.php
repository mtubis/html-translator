<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm;

/**
 * Why the model stopped answering, mapped by adapters from the vendor's value (e.g. OpenAI finish_reason, Anthropic
 * stop_reason).
 */
enum FinishReason
{
    /** The answer is complete. */
    case Stop;

    /** The answer was cut off at the output token limit. */
    case Length;

    /** The vendor's content filter blocked or truncated the answer. */
    case ContentFilter;

    /** The model declined to answer. */
    case Refusal;

    /** Anything else the vendor reports (tool calls, unknown values). */
    case Other;
}
