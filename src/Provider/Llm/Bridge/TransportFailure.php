<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm\Bridge;

use HtmlTranslator\Exception\TranslatorException;
use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * The PSR-18 client failed on the last attempt; thrown by Retrier::send(). An adapter turns it into a
 * ProviderException at once and must not keep it or its previous exception: getMessage() is the client's unredacted
 * message, and getPrevious() is the client's exception, which holds or recorded the request with the API key.
 * Redact the message, name get_debug_type($failure->getPrevious()) and mention $attempts instead (see the adapters).
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
