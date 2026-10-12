<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Profile;

use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Profile\Glossary;
use HtmlTranslator\Profile\GlossaryEntry;
use HtmlTranslator\Profile\ProfileText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Glossary::class)]
#[UsesClass(GlossaryEntry::class)]
#[UsesClass(ProfileText::class)]
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
}
