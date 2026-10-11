<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm;

enum ChatRole: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
}
