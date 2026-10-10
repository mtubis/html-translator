<?php

declare(strict_types=1);

namespace HtmlTranslator;

/**
 * What happens to a unit that still has no valid translation after corrective retries (ARCHITECTURE §4).
 */
enum FallbackStrategy
{
    /** Translate the text between its tags separately, without inline context; keep the original tags. */
    case TextNodesOnly;

    /** Leave the unit untranslated. */
    case KeepOriginal;

    /** Abort with StructureValidationException. */
    case Throw;
}
