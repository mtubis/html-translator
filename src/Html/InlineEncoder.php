<?php

declare(strict_types=1);

namespace HtmlTranslator\Html;

use Dom\Comment;
use Dom\Element;
use Dom\Node;
use Dom\Text;
use HtmlTranslator\Domain\InlineTag;
use HtmlTranslator\Domain\InlineTagKind;
use HtmlTranslator\Domain\UnitText;

/**
 * Encodes a run of inline nodes as unit text with numbered pseudo-tags (ADR-0003, ADR-0008).
 *
 * - element with content → <gN>…</gN>; void element → <xN/>
 * - skipped element (element rule or translate state "no") and comment → opaque <xN/>; nothing inside is sent,
 *   even a descendant with translate="yes" (splitting the sentence around it would be worse)
 * - text → escaped &, <, >
 *
 * Numbers follow opening order and restart for every run.
 */
final readonly class InlineEncoder
{
    private const array VOID_ELEMENTS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    /**
     * @param list<Node> $run nodes of a translating container
     */
    public function encode(array $run, SkipRules $rules): EncodedRun
    {
        $text = '';
        $plain = '';
        $inlineTags = [];
        $tagNodes = [];
        $attributeCandidates = [];

        foreach ($run as $node) {
            $this->encodeNode($node, $rules, $text, $plain, $inlineTags, $tagNodes, $attributeCandidates);
        }

        return new EncodedRun($text, $inlineTags, $tagNodes, $attributeCandidates, UnitText::hasTranslatableCharacter($plain));
    }

    /**
     * $plain collects the unescaped text that will be sent, to tell whether the run is worth translating.
     *
     * @param list<InlineTag> $inlineTags
     * @param array<int, Node> $tagNodes
     * @param list<Element> $attributeCandidates
     *
     * @param-out list<InlineTag> $inlineTags
     * @param-out array<int, Node> $tagNodes
     * @param-out list<Element> $attributeCandidates
     */
    private function encodeNode(Node $node, SkipRules $rules, string &$text, string &$plain, array &$inlineTags, array &$tagNodes, array &$attributeCandidates): void
    {
        if ($node instanceof Text) {
            $text .= UnitText::escape($node->data);
            $plain .= $node->data;

            return;
        }

        if (!$node instanceof Element && !$node instanceof Comment) {
            return;
        }

        $number = \count($inlineTags) + 1;
        $tagNodes[$number] = $node;

        if ($node instanceof Comment || $rules->skipsSubtree($node) || !$rules->translates($node, true)) {
            $inlineTags[] = InlineTag::of($number, InlineTagKind::Opaque);
            $text .= \sprintf('<x%d/>', $number);

            return;
        }

        $attributeCandidates[] = $node;

        if (\in_array($node->localName, self::VOID_ELEMENTS, true)) {
            $inlineTags[] = InlineTag::of($number, InlineTagKind::Void);
            $text .= \sprintf('<x%d/>', $number);

            return;
        }

        $inlineTags[] = InlineTag::of($number, InlineTagKind::Paired);
        $text .= \sprintf('<g%d>', $number);

        foreach ($node->childNodes as $child) {
            $this->encodeNode($child, $rules, $text, $plain, $inlineTags, $tagNodes, $attributeCandidates);
        }

        $text .= \sprintf('</g%d>', $number);
    }
}
