<?php

declare(strict_types=1);

namespace HtmlTranslator\Html;

use Dom\Element;
use HtmlTranslator\Exception\InvalidArgumentException;

/**
 * Decides whether an element's text is excluded from translation.
 *
 * Two kinds of rules, deliberately different:
 * - Element rules (script, code, svg, textarea, …) are absolute: the element's content and its whole subtree
 *   are skipped, even below translate="yes". Remove the element with withoutElement() to translate it.
 *   Translatable attributes of the skipped element itself (svg aria-label, textarea placeholder) are interface
 *   text and still translated; the Segmenter handles that.
 * - The translate attribute and skip classes are inherited as in the HTML spec: the nearest ancestor-or-self
 *   with translate="yes"/"no" (case-insensitive, empty = yes) or a skip class (= no) decides; an unknown
 *   translate value inherits. On one element the translate attribute wins over a class.
 */
final readonly class SkipRules
{
    private const string ELEMENT_NAME = '/^[a-z][a-z0-9-]*\z/';

    private const string HTML_WHITESPACE = " \t\n\f\r";

    /**
     * @param list<string> $elements lower-case local names
     * @param list<string> $classes
     */
    private function __construct(
        private array $elements,
        private array $classes,
    ) {}

    public static function defaults(): self
    {
        return new self(
            // textarea content is a form field's default value, i.e. data (interface text lives in placeholder).
            ['script', 'style', 'code', 'pre', 'kbd', 'samp', 'var', 'svg', 'math', 'template', 'textarea'],
            ['notranslate'],
        );
    }

    /**
     * @throws InvalidArgumentException when the name is not a valid element name
     */
    public function withElement(string $name): self
    {
        return new self($this->add($this->elements, $this->elementName($name)), $this->classes);
    }

    /**
     * @throws InvalidArgumentException when the name is not a valid element name
     */
    public function withoutElement(string $name): self
    {
        return new self($this->remove($this->elements, $this->elementName($name)), $this->classes);
    }

    /**
     * @throws InvalidArgumentException when the class name is empty, contains whitespace or starts with "."
     */
    public function withClass(string $class): self
    {
        return new self($this->elements, $this->add($this->classes, $this->className($class)));
    }

    /**
     * @throws InvalidArgumentException when the class name is empty, contains whitespace or starts with "."
     */
    public function withoutClass(string $class): self
    {
        return new self($this->elements, $this->remove($this->classes, $this->className($class)));
    }

    /**
     * Whether the element and everything below it is skipped by an element rule (looks at this element only).
     * Only these subtrees may be pruned: below translate="no" a descendant can switch translation back on.
     */
    public function skipsSubtree(Element $element): bool
    {
        return \in_array(strtolower($element->localName), $this->elements, true);
    }

    /**
     * The translate state of one element given its parent's, from its translate attribute and skip classes.
     * Lets a top-down walker carry the inherited state instead of calling excludes() for every element.
     */
    public function translates(Element $element, bool $parentTranslates): bool
    {
        $value = $element->getAttribute('translate');

        if (null !== $value) {
            $value = strtolower($value);

            if ('' === $value || 'yes' === $value) {
                return true;
            }

            if ('no' === $value) {
                return false;
            }
        }

        // Reading classList allocates a token list; most elements have no class at all.
        if ($element->hasAttribute('class')) {
            foreach ($this->classes as $class) {
                if ($element->classList->contains($class)) {
                    return false;
                }
            }
        }

        return $parentTranslates;
    }

    /**
     * Convenience check for a single element: walks all its ancestors, so do not call it for every node of a tree.
     * Decides about text only; the element's own attributes under an element rule are decided by the Segmenter.
     */
    public function excludes(Element $element): bool
    {
        $lineage = [];

        for ($current = $element; $current instanceof Element; $current = $current->parentElement) {
            if ($this->skipsSubtree($current)) {
                return true;
            }

            $lineage[] = $current;
        }

        $translates = true;
        foreach (array_reverse($lineage) as $current) {
            $translates = $this->translates($current, $translates);
        }

        return !$translates;
    }

    private function elementName(string $name): string
    {
        $normalized = strtolower($name);

        if (1 !== preg_match(self::ELEMENT_NAME, $normalized)) {
            throw new InvalidArgumentException(\sprintf('"%s" is not a valid element name; expected e.g. "code" or "x-foo".', $name));
        }

        return $normalized;
    }

    private function className(string $class): string
    {
        if ('' === $class || false !== strpbrk($class, self::HTML_WHITESPACE) || str_starts_with($class, '.')) {
            throw new InvalidArgumentException(\sprintf('"%s" is not a valid class name; expected e.g. "notranslate" (no dot, no whitespace).', $class));
        }

        return $class;
    }

    /**
     * @param list<string> $list
     *
     * @return list<string>
     */
    private function add(array $list, string $item): array
    {
        return \in_array($item, $list, true) ? $list : [...$list, $item];
    }

    /**
     * @param list<string> $list
     *
     * @return list<string>
     */
    private function remove(array $list, string $item): array
    {
        return array_values(array_filter($list, static fn(string $existing): bool => $existing !== $item));
    }
}
