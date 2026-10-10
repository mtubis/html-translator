<?php

declare(strict_types=1);

namespace HtmlTranslator\Exception;

use RuntimeException;

/**
 * A provider could not translate a batch at all, even after its own transport retries (e.g. the service is down).
 * Failures of single units are reported in BatchResult instead.
 */
final class ProviderException extends RuntimeException implements TranslatorException {}
