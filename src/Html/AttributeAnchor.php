<?php

declare(strict_types=1);

namespace HtmlTranslator\Html;

use Dom\Element;

/**
 * Where an attribute unit lives in the DOM.
 *
 * @internal part of the pipeline behind HtmlTranslator (ADR-0018)
 */
final readonly class AttributeAnchor
{
    public function __construct(
        public Element $element,
        public string $attribute,
    ) {}
}
