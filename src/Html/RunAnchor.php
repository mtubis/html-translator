<?php

declare(strict_types=1);

namespace HtmlTranslator\Html;

use Dom\Node;
use HtmlTranslator\Exception\InvalidArgumentException;

/**
 * Where a block or title unit lives in the DOM: the sibling nodes its translation replaces
 * and the original nodes behind its pseudo-tags (ADR-0007).
 */
final readonly class RunAnchor
{
    /**
     * @param list<Node> $nodes consecutive siblings, in document order
     * @param array<int, Node> $tags pseudo-tag number → original element (or comment, for opaque tags)
     *
     * @throws InvalidArgumentException when there are no nodes
     */
    public function __construct(
        public array $nodes,
        public array $tags,
    ) {
        if ([] === $nodes) {
            throw new InvalidArgumentException('A run anchor needs at least one node.');
        }
    }
}
