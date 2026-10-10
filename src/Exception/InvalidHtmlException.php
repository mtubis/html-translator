<?php

declare(strict_types=1);

namespace HtmlTranslator\Exception;

use InvalidArgumentException as NativeInvalidArgumentException;

/**
 * The input cannot be translated at all (e.g. it is empty).
 */
final class InvalidHtmlException extends NativeInvalidArgumentException implements TranslatorException {}
