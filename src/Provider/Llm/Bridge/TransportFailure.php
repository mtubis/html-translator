<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm\Bridge;

use HtmlTranslator\Exception\TranslatorException;
use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * The PSR-18 client failed on the last attempt. Thrown by Retrier for the adapter only, which turns it into a
 * ProviderException without keeping the client's exception (it holds or recorded the request).
 */
final class TransportFailure extends RuntimeException implements TranslatorException
{
    public function __construct(
        public readonly int $attempts,
        ClientExceptionInterface $last,
    ) {
        parent::__construct($last->getMessage(), 0, $last);
    }
}
