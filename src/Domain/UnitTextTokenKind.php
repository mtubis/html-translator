<?php

declare(strict_types=1);

namespace HtmlTranslator\Domain;

enum UnitTextTokenKind
{
    /** Decoded text. */
    case Text;

    /** <gN> */
    case Open;

    /** </gN> */
    case Close;

    /** <xN/> */
    case Void;

    /** Something that would be markup in HTML but is not a pseudo-tag, e.g. <b> or <!-- --> (ADR-0009). */
    case RawMarkup;
}
