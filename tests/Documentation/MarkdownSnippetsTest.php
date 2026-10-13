<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Documentation;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * The harness lives in tests/, outside the coverage source, hence CoversNothing.
 */
#[CoversNothing]
final class MarkdownSnippetsTest extends TestCase
{
    #[Test]
    public function it_reads_php_blocks_in_order_with_their_line(): void
    {
        $markdown = "# Title\n\n```php\necho 1;\n```\n\nText.\n\n```php\necho 2;\n```\n";

        self::assertEquals([new Snippet("echo 1;\n", 3), new Snippet("echo 2;\n", 9)], MarkdownSnippets::fromMarkdown($markdown));
    }

    #[Test]
    public function it_pairs_a_text_block_with_the_php_block_right_before_it(): void
    {
        $markdown = "```php\necho 'a';\n```\n\n```text\na\n```\n\n```php\necho 'b';\n```\n";

        self::assertEquals([new Snippet("echo 'a';\n", 1, "a\n"), new Snippet("echo 'b';\n", 9)], MarkdownSnippets::fromMarkdown($markdown));
    }

    #[Test]
    public function it_rejects_a_text_block_separated_from_its_php_block(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('The text block at line 7 does not directly follow a PHP block.');

        MarkdownSnippets::fromMarkdown("```php\necho 'a';\n```\n\nSome prose.\n\n```text\na\n```\n");
    }

    #[Test]
    public function it_rejects_a_text_block_after_another_language(): void
    {
        $this->expectException(UnexpectedValueException::class);

        MarkdownSnippets::fromMarkdown("```bash\nls\n```\n\n```text\nout\n```\n");
    }

    #[Test]
    public function it_ignores_other_languages(): void
    {
        self::assertSame([], MarkdownSnippets::fromMarkdown("```bash\ncomposer require x\n```\n\n```json\n{}\n```\n"));
    }
}
