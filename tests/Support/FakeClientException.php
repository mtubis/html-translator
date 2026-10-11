<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * A transport failure of FakeHttpClient (connection refused, timeout).
 */
final class FakeClientException extends RuntimeException implements ClientExceptionInterface {}
