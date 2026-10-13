<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Profile;

use HtmlTranslator\Profile\TermMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The detailed matching rules are covered through Glossary::matching() in GlossaryTest.
 */
#[CoversClass(TermMatcher::class)]
final class TermMatcherTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>, bool}>
     */
    public static function cases(): iterable
    {
        yield 'other case' => ['GitHub', ['Push to GITHUB.'], true];
        yield 'full case folding' => ['straße', ['STRASSE'], true];
        yield 'other whitespace' => ['pull request', ["a pull\u{00A0}\n request"], true];
        yield 'inside a longer word' => ['art', ['party'], false];
        yield 'punctuation edge' => ['.NET', ['ASP.NET'], true];
        yield 'script without spaces' => ['東京', ['東京都'], true];
        yield 'across two texts' => ['pull request', ['pull', 'request'], false];
        yield 'in a later text' => ['Laravel', ['Symfony', 'and Laravel'], true];
        yield 'no texts' => ['GitHub', [], false];
    }

    /**
     * @param list<string> $texts
     */
    #[Test]
    #[DataProvider('cases')]
    public function it_matches_whole_terms_ignoring_case_and_whitespace(string $term, array $texts, bool $expected): void
    {
        self::assertSame($expected, TermMatcher::in($texts)->matches($term));
    }

    /**
     * @return iterable<string, array{string, list<string>, bool}>
     */
    public static function caseSensitiveCases(): iterable
    {
        yield 'same case' => ['GitHub', ['Push to GitHub.'], true];
        yield 'other case' => ['GitHub', ['Push to GITHUB.'], false];
        yield 'other whitespace' => ['Symfony Flex', ["Symfony\u{00A0} Flex"], true];
        yield 'inside a longer word' => ['Git', ['GitHub'], false];
    }

    /**
     * @param list<string> $texts
     */
    #[Test]
    #[DataProvider('caseSensitiveCases')]
    public function it_can_match_with_the_same_letter_case_only(string $term, array $texts, bool $expected): void
    {
        self::assertSame($expected, TermMatcher::caseSensitiveIn($texts)->matches($term));
    }
}
