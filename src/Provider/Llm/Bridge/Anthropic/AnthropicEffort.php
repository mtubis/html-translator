<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm\Bridge\Anthropic;

/**
 * output_config.effort of the Messages API: how much the model thinks and spends on an answer. Lower is cheaper and
 * faster; translation rarely needs more than the model's default.
 */
enum AnthropicEffort: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case XHigh = 'xhigh';
    case Max = 'max';
}
