<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Profile;

use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Profile\GlossaryEntry;
use HtmlTranslator\Profile\ProfileText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GlossaryEntry::class)]
#[CoversClass(ProfileText::class)]
final class GlossaryEntryTest extends TestCase
{
    #[Test]
    public function it_trims_terms(): void
    {
        $entry = GlossaryEntry::of("  pull request \n", "\u{00A0}pull request\u{3000}");

        self::assertSame('pull request', $entry->source);
        self::assertSame('pull request', $entry->target);
    }

    #[Test]
    public function it_accepts_multibyte_terms_up_to_the_limit(): void
    {
        $term = str_repeat('ż', 200);

        $entry = GlossaryEntry::of($term, $term);

        self::assertSame($term, $entry->source);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidTerms(): iterable
    {
        yield 'empty source' => ['', 'target', 'Source term'];
        yield 'whitespace-only source' => [" \t\n", 'target', 'Source term'];
        yield 'empty target' => ['source', '', 'Target term'];
        yield 'whitespace-only target' => ['source', '   ', 'Target term'];
        yield 'non-breaking-space-only source' => ["\u{00A0}", 'target', 'Source term'];
        yield 'ideographic-space-only target' => ['source', "\u{3000}", 'Target term'];
        yield 'line break in source' => ["pull\nrequest", 'target', 'line breaks'];
        yield 'control character in target' => ['source', "tar\x07get", 'control characters'];
        yield 'line separator in target' => ['source', "tar\u{2028}get", 'line breaks'];
        yield 'source over the limit' => [str_repeat('a', 201), 'target', '200 characters'];
        yield 'multibyte target over the limit' => ['source', str_repeat('ż', 201), '200 characters'];
        yield 'invalid UTF-8 source' => ["caf\xE9", 'target', 'UTF-8'];
        yield 'invalid UTF-8 target' => ['source', "\xC3\x28", 'UTF-8'];
    }

    #[Test]
    #[DataProvider('invalidTerms')]
    public function it_rejects_invalid_terms(string $source, string $target, string $messagePart): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($messagePart);

        GlossaryEntry::of($source, $target);
    }
}
