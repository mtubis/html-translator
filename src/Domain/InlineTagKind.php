<?php

declare(strict_types=1);

namespace HtmlTranslator\Domain;

/**
 * How an inline element is represented in a unit's text (ADR-0003).
 */
enum InlineTagKind
{
    /** Element with content, sent as <gN>…</gN>. */
    case Paired;

    /** Element without content (e.g. <br>, <img>), sent as <xN/>. */
    case Void;

    /** Element matched by a skip rule (e.g. inline <code>), sent as <xN/>; its content is never sent. */
    case Opaque;
}
