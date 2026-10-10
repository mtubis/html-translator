<?php

declare(strict_types=1);

namespace HtmlTranslator\Domain;

/**
 * Where a translation unit comes from in the document.
 */
enum UnitKind
{
    /** Inline content of a block element; may contain pseudo-tags. */
    case Block;

    /** Value of a translatable attribute; plain text only. */
    case Attribute;

    /** Content of <title>; plain text only. */
    case Title;
}
