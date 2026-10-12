<?php

declare(strict_types=1);

namespace HtmlTranslator\Html;

use Dom\HTMLDocument;
use HtmlTranslator\Exception\InvalidHtmlException;

/**
 * Parses HTML with the native HTML5 parser (ADR-0006) and is the only place that decides
 * whether the input is a full document or a fragment.
 *
 * Input is always decoded as UTF-8; a <meta charset> in the document does not change that.
 * Invalid UTF-8 sequences are replaced with U+FFFD by the parser.
 *
 * Fragments are parsed in a <body> context, except when the first element is a table part outside a <table>
 * (<tr>, <td>, <thead>, <col>, …): then the context is the element it belongs in, so rows and cells of a CMS field
 * or template partial survive instead of being dropped. The first element decides; later content is parsed as it
 * would be inside that element (e.g. a <p> after rows stays where it is, while <thead> followed by <tr> gets an
 * implied <tbody>, as in a browser).
 */
final class HtmlLoader
{
    private const string BYTE_ORDER_MARK = "\u{FEFF}";

    private const string HTML_WHITESPACE = " \t\n\f\r";

    /**
     * Start of a comment or of a processing instruction / XML declaration, anchored at the current offset.
     */
    private const string PROLOG_ITEM = '/\G<(?:!--|\?)/';

    /**
     * Anchored at the first offset after the prolog. The lookahead keeps <header> and custom elements
     * such as <html-widget> out; a doctype needs none (the parser accepts "<!DOCTYPEhtml>").
     */
    private const string DOCUMENT_START = '/\G<(?:!doctype|(?:html|head|body)(?=[\s>\/]|\z))/i';

    /**
     * A table part opening the content, anchored like DOCUMENT_START; the lookahead keeps custom elements such as
     * <tr-row> out.
     */
    private const string TABLE_PART_START = '/\G<(tr|td|th|thead|tbody|tfoot|caption|colgroup|col)(?=[\s>\/]|\z)/i';

    /**
     * Table part → the elements to parse it in, outermost first (under <body>).
     */
    private const array TABLE_CONTEXTS = [
        'tr' => ['table', 'tbody'],
        'td' => ['table', 'tbody', 'tr'],
        'th' => ['table', 'tbody', 'tr'],
        'thead' => ['table'],
        'tbody' => ['table'],
        'tfoot' => ['table'],
        'caption' => ['table'],
        'colgroup' => ['table'],
        'col' => ['table', 'colgroup'],
    ];

    /**
     * @throws InvalidHtmlException when the input is empty or whitespace only
     */
    public function load(string $html): LoadedDocument
    {
        if (str_starts_with($html, self::BYTE_ORDER_MARK)) {
            $html = substr($html, \strlen(self::BYTE_ORDER_MARK));
        }

        if ('' === trim($html, self::HTML_WHITESPACE)) {
            throw new InvalidHtmlException('Cannot translate empty HTML.');
        }

        $contentStart = $this->contentStart($html);
        if (null !== $contentStart && 1 === preg_match(self::DOCUMENT_START, $html, offset: $contentStart)) {
            return LoadedDocument::document(HTMLDocument::createFromString($html, \LIBXML_NOERROR, 'UTF-8'));
        }

        $context = [];
        if (null !== $contentStart && 1 === preg_match(self::TABLE_PART_START, $html, $match, offset: $contentStart)) {
            $context = self::TABLE_CONTEXTS[strtolower($match[1])] ?? [];
        }

        return $this->loadFragment($html, $context);
    }

    /**
     * Skips whitespace, comments and processing instructions / XML declarations (which the parser
     * turns into comments) in one linear pass and returns the offset of what opens the content; null when an
     * unterminated comment swallows the rest, so no markup can follow.
     * Every regex here is anchored and bounded: a lazy "<!--.*?-->" hits pcre.backtrack_limit on long comments.
     */
    private function contentStart(string $html): ?int
    {
        $offset = 0;

        while (true) {
            $offset += strspn($html, self::HTML_WHITESPACE, $offset);

            if (1 !== preg_match(self::PROLOG_ITEM, $html, $match, 0, $offset)) {
                break;
            }

            $closing = '<?' === $match[0] ? '>' : '-->';

            // Searching right after "<!" lets the abrupt comments "<!-->" and "<!--->" end where the parser ends them.
            $end = strpos($html, $closing, $offset + 2);
            if (false === $end) {
                // Unterminated: everything that follows is comment text.
                return null;
            }

            $offset = $end + \strlen($closing);
        }

        return $offset;
    }

    /**
     * @param list<string> $context elements to parse the fragment in, under <body> (outermost first)
     */
    private function loadFragment(string $html, array $context): LoadedDocument
    {
        $document = HTMLDocument::createEmpty();
        $root = $document->createElement('html');
        $container = $document->createElement('body');
        $root->appendChild($container);
        $document->appendChild($root);

        foreach ($context as $name) {
            $element = $document->createElement($name);
            $container->appendChild($element);
            $container = $element;
        }

        // Runs the HTML fragment parsing algorithm with the innermost element as context; it does not emit
        // parse-error warnings. The container is attached so document-wide queries and absolute XPath work as for
        // full documents.
        $container->innerHTML = $html;

        return LoadedDocument::fragment($document, $container);
    }
}
