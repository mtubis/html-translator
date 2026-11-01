<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Profile;

use HtmlTranslator\Exception\ArrayArgument;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\UntrustedText;
use HtmlTranslator\Profile\Glossary;
use HtmlTranslator\Profile\GlossaryEntry;
use HtmlTranslator\Profile\ProfileText;
use HtmlTranslator\Profile\TermMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Glossary::class)]
#[CoversClass(TermMatcher::class)]
#[UsesClass(GlossaryEntry::class)]
#[UsesClass(ProfileText::class)]
#[UsesClass(ArrayArgument::class)]
#[UsesClass(UntrustedText::class)]
final class GlossaryTest extends TestCase
{
    #[Test]
    public function it_is_empty_by_default(): void
    {
        $glossary = Glossary::empty();

        self::assertTrue($glossary->isEmpty());
        self::assertSame([], $glossary->entries());
    }

    #[Test]
    public function it_keeps_entries_in_insertion_order(): void
    {
        $fromArray = Glossary::fromArray(['pull request' => 'pull request', 'commit' => 'commit', 'branch' => 'gałąź']);
        $of = Glossary::of(GlossaryEntry::of('pull request', 'pull request'), GlossaryEntry::of('commit', 'commit'), GlossaryEntry::of('branch', 'gałąź'));

        $expected = [['pull request', 'pull request'], ['commit', 'commit'], ['branch', 'gałąź']];
        self::assertSame($expected, $this->pairs($fromArray));
        self::assertSame($expected, $this->pairs($of));
        self::assertFalse($fromArray->isEmpty());
    }

    #[Test]
    public function it_casts_numeric_source_keys_to_strings(): void
    {
        $glossary = Glossary::fromArray(['404' => 'nie znaleziono']);

        self::assertSame([['404', 'nie znaleziono']], $this->pairs($glossary));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function caseInsensitiveDuplicates(): iterable
    {
        yield 'ASCII' => ['API', 'api'];
        yield 'multibyte' => ['Żółw', 'żółw'];
        yield 'same after trimming' => ['commit', ' Commit '];
        yield 'same after trimming Unicode whitespace' => ['commit', "commit\u{00A0}"];
    }

    #[Test]
    #[DataProvider('caseInsensitiveDuplicates')]
    public function it_rejects_source_terms_repeated_case_insensitively(string $first, string $second): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('more than once');

        Glossary::fromArray([$first => 'a', $second => 'b']);
    }

    #[Test]
    public function it_allows_the_same_target_for_different_source_terms(): void
    {
        $glossary = Glossary::fromArray(['repo' => 'repozytorium', 'repository' => 'repozytorium']);

        self::assertSame([['repo', 'repozytorium'], ['repository', 'repozytorium']], $this->pairs($glossary));
    }

    #[Test]
    public function it_accepts_500_entries(): void
    {
        self::assertCount(500, Glossary::fromArray($this->terms(500))->entries());
    }

    #[Test]
    public function it_rejects_more_than_500_entries(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('500');

        Glossary::fromArray($this->terms(501));
    }

    #[Test]
    public function it_returns_a_new_glossary_from_with(): void
    {
        $original = Glossary::fromArray(['commit' => 'commit', 'merge' => 'merge']);

        $extended = $original->with(GlossaryEntry::of('branch', 'gałąź'));

        self::assertSame([['commit', 'commit'], ['merge', 'merge']], $this->pairs($original));
        self::assertSame([['commit', 'commit'], ['merge', 'merge'], ['branch', 'gałąź']], $this->pairs($extended));
    }

    #[Test]
    public function it_lists_entries_spread_with_string_keys(): void
    {
        $glossary = Glossary::of(...['first' => GlossaryEntry::of('commit', 'commit'), 'second' => GlossaryEntry::of('branch', 'gałąź')]);

        self::assertTrue(array_is_list($glossary->entries()));
    }

    #[Test]
    public function it_rejects_a_duplicate_added_with_with(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Glossary::fromArray(['commit' => 'commit'])->with(GlossaryEntry::of('COMMIT', 'zatwierdzenie'));
    }

    #[Test]
    public function it_rejects_an_invalid_term_from_an_array(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Glossary::fromArray(['commit' => ' ']);
    }

    /**
     * @return iterable<string, array{list<string>, string, bool}>
     */
    public static function termOccurrences(): iterable
    {
        yield 'exact' => [['Open a pull request.'], 'pull request', true];
        yield 'different case' => [['Open a Pull Request.'], 'pull request', true];
        yield 'inside a longer word' => [['Join the party.'], 'art', false];
        yield 'as a prefix of a longer word' => [['Artists welcome.'], 'art', false];
        yield 'as a suffix of a longer word' => [['Restart now.'], 'start', false];
        yield 'at the start' => [['Commit often.'], 'commit', true];
        yield 'at the end' => [['Then commit'], 'commit', true];
        yield 'whole text' => [['commit'], 'commit', true];
        yield 'extra whitespace between words' => [["Open a pull  \n request."], 'pull request', true];
        yield 'next to punctuation' => [['(branch), "branch".'], 'branch', true];
        yield 'with digits around' => [['Use 2FA2 here.'], 'FA', false];
        yield 'in the second text' => [['Nothing here.', 'Create a branch.'], 'branch', true];
        yield 'Cyrillic' => [['Наш сервер работает.'], 'сервер', true];
        yield 'Cyrillic inside a longer word' => [['Серверная готова.'], 'сервер', false];
        yield 'Polish upper-case multibyte' => [['UWAGA: ŻÓŁW!'], 'żółw', true];
        yield 'Japanese inside a sentence' => [['設定を変更する'], '設定', true];
        yield 'Chinese inside a sentence' => [['请打开设置页面'], '设置', true];
        yield 'regex characters' => [['Written in C++ and .NET.'], 'C++', true];
        yield 'regex characters not as a pattern' => [['Written in Cxx.'], 'C++', false];
        yield 'dot not as a wildcard' => [['Use XNET today.'], '.NET', false];
        yield 'term starting with punctuation after a word' => [['Built on ASP.NET Core.'], '.NET', true];
        yield 'term ending with punctuation before a word' => [['Compiled as C++20.'], 'C++', true];
        yield 'German sharp s by case folding' => [['STRASSE GESPERRT'], 'straße', true];
        yield 'Greek final sigma' => [['ΛΌΓΟΣ'], 'λόγος', true];
        yield 'mixed script term needs a boundary on its Latin side' => [['XAPI設定を開く'], 'API設定', false];
        yield 'no texts' => [[], 'commit', false];
        yield 'empty text' => [[''], 'commit', false];
    }

    /**
     * @param list<string> $texts
     */
    #[Test]
    #[DataProvider('termOccurrences')]
    public function it_matches_entries_found_in_texts(array $texts, string $term, bool $expected): void
    {
        $matching = Glossary::fromArray([$term => 'x'])->matching($texts);

        self::assertSame($expected, !$matching->isEmpty());
    }

    #[Test]
    public function it_keeps_insertion_order_and_returns_each_entry_once_when_matching(): void
    {
        $glossary = Glossary::fromArray(['commit' => 'commit', 'merge' => 'scalenie', 'branch' => 'gałąź']);

        $matching = $glossary->matching(['Create a branch, then commit.', 'Commit again on the branch.']);

        self::assertSame([['commit', 'commit'], ['branch', 'gałąź']], $this->pairs($matching));
    }

    /**
     * @return list<array{string, string}>
     */
    private function pairs(Glossary $glossary): array
    {
        return array_map(static fn(GlossaryEntry $entry): array => [$entry->source, $entry->target], $glossary->entries());
    }

    /**
     * @return array<string, string>
     */
    private function terms(int $count): array
    {
        $terms = [];
        for ($i = 1; $i <= $count; ++$i) {
            $terms['term ' . $i] = 'termin ' . $i;
        }

        return $terms;
    }

    #[Test]
    public function it_rejects_a_target_that_is_not_a_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('terms["pull request"] must be a string, got int.');

        // @phpstan-ignore argument.type (deliberately wrong input: the runtime check is what is under test)
        Glossary::fromArray(['pull request' => 1]);
    }

    #[Test]
    public function it_rejects_a_list_where_a_map_of_terms_is_expected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('terms must map source terms to target terms, got a list.');

        Glossary::fromArray(['pull request', 'merge request']);
    }

    #[Test]
    public function it_rejects_texts_to_match_that_are_not_strings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('texts[1] must be a string, got int.');

        // @phpstan-ignore argument.type (deliberately wrong input: the runtime check is what is under test)
        Glossary::fromArray(['a' => 'b'])->matching(['a', 1]);
    }

    #[Test]
    public function it_rejects_texts_to_match_that_are_not_a_list(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('texts must be a list, got key "x".');

        // @phpstan-ignore argument.type (deliberately wrong input: the runtime check is what is under test)
        Glossary::fromArray(['a' => 'b'])->matching(['x' => 'a']);
    }

    /**
     * PHP cannot tell ['0' => 'zero'] from a list, so it is rejected like a list; of() builds such a glossary (#78).
     */
    #[Test]
    public function it_rejects_source_terms_that_make_a_list_and_builds_them_with_of(): void
    {
        self::assertSame(['0'], array_map(static fn(GlossaryEntry $entry): string => $entry->source, Glossary::of(GlossaryEntry::of('0', 'zero'))->entries()));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('terms must map source terms to target terms, got a list.');

        Glossary::fromArray(['0' => 'zero']);
    }

    #[Test]
    public function it_takes_a_numeric_source_term_from_an_array_key(): void
    {
        self::assertSame(['2024'], array_map(static fn(GlossaryEntry $entry): string => $entry->source, Glossary::fromArray(['2024' => 'MMXXIV'])->entries()));
    }
}
