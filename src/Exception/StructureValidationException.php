<?php

declare(strict_types=1);

namespace HtmlTranslator\Exception;

use RuntimeException;

/**
 * A unit could not be translated with a valid structure and FallbackStrategy::Throw was selected.
 */
final class StructureValidationException extends RuntimeException implements TranslatorException {}
