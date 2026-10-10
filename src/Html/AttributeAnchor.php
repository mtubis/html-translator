<?php

declare(strict_types=1);

namespace HtmlTranslator\Html;

use Dom\Element;

/**
 * Where an attribute unit lives in the DOM.
 */
final readonly class AttributeAnchor
{
    public function __construct(
        public Element $element,
        public string $attribute,
    ) {}
}
