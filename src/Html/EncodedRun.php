<?php

declare(strict_types=1);

namespace HtmlTranslator\Html;

use Dom\Element;
use Dom\Node;
use HtmlTranslator\Domain\InlineTag;

/**
 * Result of encoding one inline run: unit text with pseudo-tags and what the tags stand for.
 *
 * @internal
 */
final readonly class EncodedRun
{
    /**
     * @param list<InlineTag> $inlineTags
     * @param array<int, Node> $tagNodes pseudo-tag number → original node (element or comment)
     * @param list<Element> $attributeCandidates encoded (non-opaque) elements whose attributes may become units
     */
    public function __construct(
        public string $text,
        public array $inlineTags,
        public array $tagNodes,
        public array $attributeCandidates,
        public bool $hasTranslatableText,
    ) {}
}
