<?php

declare(strict_types=1);

namespace HtmlTranslator\Exception;

use LogicException as NativeLogicException;

/**
 * A programming or setup error, e.g. a test double used beyond what it was scripted for. Unlike ProviderException,
 * the core never turns it into an issue, so it surfaces.
 */
final class LogicException extends NativeLogicException implements TranslatorException {}
