<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm\Bridge;

use HtmlTranslator\Exception\InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;

/**
 * The last response of a request and how many attempts it took.
 */
final readonly class RetriedResponse
{
    /**
     * @throws InvalidArgumentException when there was no attempt
     */
    public function __construct(
        public ResponseInterface $response,
        public int $attempts,
    ) {
        if ($attempts < 1) {
            throw new InvalidArgumentException(\sprintf('A response needs at least 1 attempt, got %d.', $attempts));
        }
    }
}
