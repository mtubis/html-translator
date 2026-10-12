<?php

declare(strict_types=1);

namespace HtmlTranslator\Profile;

/**
 * How formally the translation addresses the reader. Backed by strings so configuration can use Formality::from().
 */
enum Formality: string
{
    /** No instruction: the model follows the register of the source text. */
    case Default = 'default';

    /** Formal register, e.g. "Sie" in German, "Pan/Pani" in Polish, "vous" in French. */
    case Formal = 'formal';

    /** Informal register, e.g. "du" in German, "ty" in Polish, "tu" in French. */
    case Informal = 'informal';
}
