<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm\Bridge;

use Psr\Http\Message\ResponseInterface;

/**
 * The last response of a request and how many attempts it took.
 */
final readonly class RetriedResponse
{
    public function __construct(
        public ResponseInterface $response,
        public int $attempts,
    ) {}
}
