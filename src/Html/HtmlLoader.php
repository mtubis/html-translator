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
 * Known limitation: fragments are parsed in a <body> context, so a fragment that starts with
 * table parts (<tr>, <td>, …) outside a <table> loses those elements, as it would in a browser.
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

        if ($this->isDocument($html)) {
            return LoadedDocument::document(HTMLDocument::createFromString($html, \LIBXML_NOERROR, 'UTF-8'));
        }

        return $this->loadFragment($html);
    }

    /**
     * Skips whitespace, comments and processing instructions / XML declarations (which the parser
     * turns into comments) in one linear pass, then looks at what opens the content.
     * Every regex here is anchored and bounded: a lazy "<!--.*?-->" hits pcre.backtrack_limit on long comments.
     */
    private function isDocument(string $html): bool
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
                // Unterminated: everything that follows is comment text, so no document markup can follow.
                return false;
            }

            $offset = $end + \strlen($closing);
        }

        return 1 === preg_match(self::DOCUMENT_START, $html, offset: $offset);
    }

    private function loadFragment(string $html): LoadedDocument
    {
        $document = HTMLDocument::createEmpty();
        $root = $document->createElement('html');
        $container = $document->createElement('body');
        $root->appendChild($container);
        $document->appendChild($root);

        // Runs the HTML fragment parsing algorithm with <body> as context; it does not emit parse-error warnings.
        // The container is attached so document-wide queries and absolute XPath work as for full documents.
        $container->innerHTML = $html;

        return LoadedDocument::fragment($document, $container);
    }
}
