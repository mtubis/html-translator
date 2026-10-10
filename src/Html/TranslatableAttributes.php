<?php

declare(strict_types=1);

namespace HtmlTranslator\Html;

use Dom\Element;
use HtmlTranslator\Exception\InvalidArgumentException;

/**
 * Decides which attributes of an element become attribute units (ARCHITECTURE §6.2).
 *
 * - Listed attributes (alt, title, …) are translatable on any element, except title on link, style, script
 *   and template, where it is not text for people (it names stylesheet sets).
 * - <meta content> is translatable when the meta's name or property is listed (case-insensitive).
 * - <input value> is translatable only where it is a visible label and not form data: always for
 *   type="button" and type="reset" (never submitted), for type="submit" only without a name attribute
 *   (with a name, the value is submitted and backends often compare it). This rule is fixed.
 *
 * Whether the element itself is excluded (translate="no", skipped subtree) is not checked here;
 * the Segmenter combines this with SkipRules. Elements are matched by local name regardless of
 * namespace; <meta>/<input> in SVG are unreachable while svg is a default skip element.
 */
final readonly class TranslatableAttributes
{
    private const string ATTRIBUTE_NAME = '/^[a-z_:][a-z0-9_.:-]*\z/';

    private const string HTML_WHITESPACE = " \t\n\f\r";

    private const array LABEL_INPUT_TYPES = ['button', 'reset'];

    /**
     * Elements whose title is not text for people: on style/link it names a stylesheet set
     * (matched by <meta http-equiv="default-style">), on script/template it is never shown.
     */
    private const array NON_TEXT_TITLE_ELEMENTS = ['link', 'script', 'style', 'template'];

    /**
     * @param list<string> $attributes lower-case attribute names
     * @param list<string> $metaNames lower-case meta name / property values
     */
    private function __construct(
        private array $attributes,
        private array $metaNames,
    ) {}

    public static function defaults(): self
    {
        return new self(
            ['alt', 'title', 'placeholder', 'aria-label', 'aria-description'],
            ['description', 'keywords', 'og:title', 'og:description'],
        );
    }

    /**
     * @throws InvalidArgumentException when the name is not a valid attribute name
     */
    public function withAttribute(string $name): self
    {
        return new self($this->add($this->attributes, $this->attributeName($name)), $this->metaNames);
    }

    /**
     * @throws InvalidArgumentException when the name is not a valid attribute name
     */
    public function withoutAttribute(string $name): self
    {
        return new self($this->remove($this->attributes, $this->attributeName($name)), $this->metaNames);
    }

    /**
     * @throws InvalidArgumentException when the name is empty or contains whitespace
     */
    public function withMetaName(string $name): self
    {
        return new self($this->attributes, $this->add($this->metaNames, $this->metaName($name)));
    }

    /**
     * @throws InvalidArgumentException when the name is empty or contains whitespace
     */
    public function withoutMetaName(string $name): self
    {
        return new self($this->attributes, $this->remove($this->metaNames, $this->metaName($name)));
    }

    /**
     * Names of the element's attributes to translate, in document order; blank values are left out.
     *
     * @return list<string>
     */
    public function namesFor(Element $element): array
    {
        $names = [];

        foreach ($element->attributes as $attribute) {
            if ($this->isTranslatable($element, $attribute->name) && '' !== trim($attribute->value, self::HTML_WHITESPACE)) {
                $names[] = $attribute->name;
            }
        }

        return $names;
    }

    private function isTranslatable(Element $element, string $attribute): bool
    {
        return match (true) {
            'title' === $attribute && \in_array($element->localName, self::NON_TEXT_TITLE_ELEMENTS, true) => false,
            \in_array($attribute, $this->attributes, true) => true,
            'content' === $attribute => 'meta' === $element->localName && $this->hasListedMetaName($element),
            'value' === $attribute => 'input' === $element->localName && $this->isInputLabel($element),
            default => false,
        };
    }

    private function isInputLabel(Element $input): bool
    {
        $type = strtolower($input->getAttribute('type') ?? '');

        return \in_array($type, self::LABEL_INPUT_TYPES, true)
            || ('submit' === $type && !$input->hasAttribute('name'));
    }

    /**
     * name and property may hold several whitespace-separated values (RDFa); any listed one matches.
     */
    private function hasListedMetaName(Element $meta): bool
    {
        foreach (['name', 'property'] as $key) {
            $tokens = preg_split('/[ \t\n\f\r]+/', strtolower($meta->getAttribute($key) ?? ''), -1, \PREG_SPLIT_NO_EMPTY);

            if (false !== $tokens && [] !== array_intersect($tokens, $this->metaNames)) {
                return true;
            }
        }

        return false;
    }

    private function attributeName(string $name): string
    {
        $normalized = strtolower($name);

        if (1 !== preg_match(self::ATTRIBUTE_NAME, $normalized)) {
            throw new InvalidArgumentException(\sprintf('"%s" is not a valid attribute name; expected e.g. "alt" or "data-tooltip".', $name));
        }

        return $normalized;
    }

    private function metaName(string $name): string
    {
        if ('' === $name || false !== strpbrk($name, self::HTML_WHITESPACE)) {
            throw new InvalidArgumentException(\sprintf('"%s" is not a valid meta name; expected e.g. "description" or "og:title".', $name));
        }

        return strtolower($name);
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
