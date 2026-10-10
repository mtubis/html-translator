<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Html;

use Dom\Element;
use Dom\HTMLDocument;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Html\SkipRules;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkipRules::class)]
#[UsesClass(InvalidArgumentException::class)]
final class SkipRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function defaultElements(): iterable
    {
        foreach (['script', 'style', 'code', 'pre', 'kbd', 'samp', 'var', 'template', 'textarea'] as $name) {
            yield $name => [$name];
        }
    }

    #[Test]
    #[DataProvider('defaultElements')]
    public function it_excludes_default_elements(string $name): void
    {
        self::assertTrue(SkipRules::defaults()->excludes($this->target(\sprintf('<p><%s id="target">x</%1$s></p>', $name))));
    }

    /**
     * Raw-text elements (script, style) and template contents have no element descendants in the DOM.
     *
     * @return iterable<string, array{string}>
     */
    public static function defaultElementsWithDescendants(): iterable
    {
        foreach (['code', 'pre', 'kbd', 'samp', 'var'] as $name) {
            yield $name => [$name];
        }
    }

    #[Test]
    #[DataProvider('defaultElementsWithDescendants')]
    public function it_excludes_descendants_of_default_elements(string $name): void
    {
        self::assertTrue(SkipRules::defaults()->excludes($this->target(\sprintf('<%s><b id="target">x</b></%1$s>', $name))));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function cases(): iterable
    {
        yield 'plain paragraph' => ['<p id="target">x</p>', false];
        yield 'inline element in a paragraph' => ['<p>a <b id="target">b</b></p>', false];

        yield 'svg' => ['<p><svg id="target"><circle r="1"></circle></svg></p>', true];
        yield 'svg descendant' => ['<svg><text id="target">x</text></svg>', true];
        yield 'html inside svg foreignObject' => ['<svg><foreignObject><p id="target">x</p></foreignObject></svg>', true];
        yield 'math' => ['<math id="target"><mi>x</mi></math>', true];
        yield 'math descendant' => ['<math><mi id="target">x</mi></math>', true];

        yield 'translate=no on the element' => ['<p id="target" translate="no">x</p>', true];
        yield 'translate=no on an ancestor' => ['<div translate="no"><p><b id="target">x</b></p></div>', true];
        yield 'translate=no on html' => ['<html translate="no"><body><p id="target">x</p></body></html>', true];
        yield 'translate value is case-insensitive' => ['<div TRANSLATE="No"><p id="target">x</p></div>', true];
        yield 'translate=yes on the element re-enables' => ['<div translate="no"><p id="target" translate="yes">x</p></div>', false];
        yield 'translate=yes on an ancestor re-enables' => ['<div translate="no"><section translate="yes"><p id="target">x</p></section></div>', false];
        yield 'translate=YES is case-insensitive' => ['<div translate="no"><p id="target" translate="YES">x</p></div>', false];
        yield 'empty translate means yes' => ['<div translate="no"><p id="target" translate="">x</p></div>', false];
        yield 'unknown translate value inherits no' => ['<div translate="no"><p id="target" translate="maybe">x</p></div>', true];
        yield 'unknown translate value inherits default' => ['<p id="target" translate="maybe">x</p>', false];
        yield 'no, yes, no again' => ['<div translate="no"><div translate="yes"><p translate="no"><b id="target">x</b></p></div></div>', true];
        yield 'yes deep inside no' => ['<div translate="no"><div><div translate="yes"><p><b id="target">x</b></p></div></div></div>', false];

        yield 'notranslate class on the element' => ['<p id="target" class="notranslate">x</p>', true];
        yield 'notranslate class on an ancestor' => ['<div class="a notranslate b"><p id="target">x</p></div>', true];
        yield 'class match is case-sensitive like CSS' => ['<div class="NoTranslate"><p id="target">x</p></div>', false];
        yield 'translate=yes below notranslate re-enables' => ['<div class="notranslate"><p id="target" translate="yes">x</p></div>', false];
        yield 'translate=yes wins over notranslate on the same element' => ['<p id="target" class="notranslate" translate="yes">x</p>', false];
        yield 'translate=no wins over nothing on the same element' => ['<p id="target" class="other" translate="no">x</p>', true];

        yield 'translate=yes does not lift an element rule' => ['<code translate="yes"><b id="target">x</b></code>', true];
        yield 'translate=yes on a skipped element itself' => ['<p><code id="target" translate="yes">x</code></p>', true];
        yield 'element rule above translate=yes' => ['<pre><span translate="yes"><b id="target">x</b></span></pre>', true];

        yield 'unknown translate value lets the class decide' => ['<p id="target" translate="maybe" class="notranslate">x</p>', true];
        yield 'translate value is not trimmed' => ['<div translate=" no "><p id="target">x</p></div>', false];
        yield 'notranslate on html' => ['<html class="notranslate"><body><p id="target">x</p></body></html>', true];
    }

    #[Test]
    #[DataProvider('cases')]
    public function it_applies_default_rules(string $html, bool $excluded): void
    {
        self::assertSame($excluded, SkipRules::defaults()->excludes($this->target($html)));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function subtreeCases(): iterable
    {
        yield 'skipped element itself' => ['<p><code id="target">x</code></p>', true];
        yield 'svg itself' => ['<p><svg id="target"></svg></p>', true];
        yield 'descendant of a skipped element' => ['<code><b id="target">x</b></code>', false];
        yield 'translate=no is not a subtree skip' => ['<p id="target" translate="no">x</p>', false];
        yield 'notranslate is not a subtree skip' => ['<p id="target" class="notranslate">x</p>', false];
    }

    /**
     * Only element rules allow pruning a subtree; translate="no" may be lifted by a descendant.
     */
    #[Test]
    #[DataProvider('subtreeCases')]
    public function it_tells_which_elements_skip_their_whole_subtree(string $html, bool $skips): void
    {
        self::assertSame($skips, SkipRules::defaults()->skipsSubtree($this->target($html)));
    }

    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function translateCases(): iterable
    {
        yield 'no setting inherits yes' => ['<p id="target">x</p>', true, true];
        yield 'no setting inherits no' => ['<p id="target">x</p>', false, false];
        yield 'translate=no' => ['<p id="target" translate="no">x</p>', true, false];
        yield 'translate=yes' => ['<p id="target" translate="yes">x</p>', false, true];
        yield 'empty translate' => ['<p id="target" translate="">x</p>', false, true];
        yield 'unknown value inherits yes' => ['<p id="target" translate="maybe">x</p>', true, true];
        yield 'unknown value inherits no' => ['<p id="target" translate="maybe">x</p>', false, false];
        yield 'skip class' => ['<p id="target" class="notranslate">x</p>', true, false];
        yield 'translate=yes wins over a skip class' => ['<p id="target" class="notranslate" translate="yes">x</p>', false, true];
        yield 'ancestors are not consulted' => ['<div translate="no"><p id="target">x</p></div>', true, true];
    }

    /**
     * Lets a top-down walker carry the inherited state instead of walking ancestors for every element.
     */
    #[Test]
    #[DataProvider('translateCases')]
    public function it_resolves_the_translate_state_of_one_element(string $html, bool $parentTranslates, bool $expected): void
    {
        self::assertSame($expected, SkipRules::defaults()->translates($this->target($html), $parentTranslates));
    }

    #[Test]
    public function it_excludes_added_elements_case_insensitively(): void
    {
        $rules = SkipRules::defaults()->withElement('X-Foo');

        self::assertTrue($rules->excludes($this->target('<x-foo><b id="target">x</b></x-foo>')));
        self::assertTrue($rules->excludes($this->target('<X-FOO id="target">x</X-FOO>')));
    }

    #[Test]
    public function it_matches_mixed_case_svg_element_names(): void
    {
        $rules = SkipRules::defaults()->withoutElement('svg')->withElement('textPath');

        self::assertTrue($rules->excludes($this->target('<svg><text><textPath id="target">x</textPath></text></svg>')));
        self::assertFalse($rules->excludes($this->target('<svg><text id="target">x</text></svg>')));
    }

    #[Test]
    public function it_translates_removed_elements(): void
    {
        $rules = SkipRules::defaults()->withoutElement('code');

        self::assertFalse($rules->excludes($this->target('<p><code id="target">x</code></p>')));
        self::assertTrue($rules->excludes($this->target('<p><kbd id="target">x</kbd></p>')));
    }

    #[Test]
    public function it_excludes_added_classes(): void
    {
        $rules = SkipRules::defaults()->withClass('no-i18n');

        self::assertTrue($rules->excludes($this->target('<div class="no-i18n"><p id="target">x</p></div>')));
        self::assertTrue($rules->excludes($this->target('<div class="notranslate"><p id="target">x</p></div>')));
    }

    #[Test]
    public function it_translates_removed_classes(): void
    {
        $rules = SkipRules::defaults()->withoutClass('notranslate');

        self::assertFalse($rules->excludes($this->target('<div class="notranslate"><p id="target">x</p></div>')));
        self::assertTrue($rules->excludes($this->target('<div translate="no"><p id="target">x</p></div>')));
    }

    #[Test]
    #[DataProvider('defaultElements')]
    public function it_keeps_default_elements_when_adding_rules(string $name): void
    {
        $rules = SkipRules::defaults()->withElement('x-foo')->withClass('no-i18n');

        self::assertTrue($rules->excludes($this->target(\sprintf('<p><%s id="target">x</%1$s></p>', $name))));
    }

    #[Test]
    public function it_keeps_default_classes_when_adding_classes(): void
    {
        $rules = SkipRules::defaults()->withClass('no-i18n')->withClass('skip-me');

        self::assertTrue($rules->excludes($this->target('<div class="notranslate"><p id="target">x</p></div>')));
        self::assertTrue($rules->excludes($this->target('<div class="no-i18n"><p id="target">x</p></div>')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rawTextElements(): iterable
    {
        foreach (['script', 'style', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript', 'plaintext', 'template'] as $name) {
            yield $name => [$name];
        }
    }

    /**
     * Raw-text elements serialize their text unescaped, so translated text in them could become markup;
     * template content is not reachable as children, so it cannot be translated in place.
     */
    #[Test]
    #[DataProvider('rawTextElements')]
    public function it_always_skips_raw_text_elements(string $name): void
    {
        $rules = SkipRules::defaults()->withoutElement($name);

        self::assertTrue($rules->skipsSubtree($this->target(\sprintf('<p><%s id="target">x</%1$s></p>', $name))));
    }

    #[Test]
    public function it_is_immutable(): void
    {
        $defaults = SkipRules::defaults();
        $code = $this->target('<p><code id="target">x</code></p>');
        $custom = $this->target('<p><x-foo id="target">x</x-foo></p>');
        $classed = $this->target('<p class="no-i18n" id="target">x</p>');

        $changed = $defaults->withoutElement('code')->withElement('x-foo')->withClass('no-i18n');

        self::assertNotSame($defaults, $changed);
        self::assertTrue($defaults->excludes($code));
        self::assertFalse($defaults->excludes($custom));
        self::assertFalse($defaults->excludes($classed));
        self::assertFalse($changed->excludes($code));
        self::assertTrue($changed->excludes($custom));
        self::assertTrue($changed->excludes($classed));
    }

    #[Test]
    public function it_ignores_duplicates_and_unknown_removals(): void
    {
        $rules = SkipRules::defaults()->withElement('code')->withoutElement('code')->withoutElement('x-unknown')->withoutClass('unknown');

        self::assertFalse($rules->excludes($this->target('<p><code id="target">x</code></p>')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidElementNames(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => [' '];
        yield 'selector syntax' => ['div.notranslate'];
        yield 'leading digit' => ['1p'];
        yield 'inner space' => ['x foo'];
    }

    #[Test]
    #[DataProvider('invalidElementNames')]
    public function it_rejects_invalid_element_names(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        SkipRules::defaults()->withElement($name);
    }

    #[Test]
    #[DataProvider('invalidElementNames')]
    public function it_rejects_invalid_element_names_on_removal(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        SkipRules::defaults()->withoutElement($name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidClassNames(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['a b'];
        yield 'tab' => ["a\tb"];
        yield 'leading dot' => ['.notranslate'];
    }

    #[Test]
    #[DataProvider('invalidClassNames')]
    public function it_rejects_invalid_class_names(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        SkipRules::defaults()->withClass($name);
    }

    #[Test]
    #[DataProvider('invalidClassNames')]
    public function it_rejects_invalid_class_names_on_removal(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        SkipRules::defaults()->withoutClass($name);
    }

    private function target(string $html): Element
    {
        $document = HTMLDocument::createFromString('<!DOCTYPE html>' . $html, \LIBXML_NOERROR, 'UTF-8');

        // querySelector, not getElementById: PHP 8.4 does not find SVG/MathML elements by id.
        return $document->querySelector('#target') ?? self::fail('Test HTML has no #target element.');
    }
}
