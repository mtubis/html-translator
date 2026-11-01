<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit;

use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Exception\ArrayArgument;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\UntrustedText;
use HtmlTranslator\Issue;
use HtmlTranslator\IssueType;
use HtmlTranslator\TranslatedDocument;
use HtmlTranslator\TranslationStats;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslatedDocument::class)]
#[UsesClass(TranslationStats::class)]
#[UsesClass(Issue::class)]
#[UsesClass(UnitId::class)]
#[UsesClass(ArrayArgument::class)]
#[UsesClass(InvalidArgumentException::class)]
#[UsesClass(UntrustedText::class)]
final class TranslatedDocumentTest extends TestCase
{
    #[Test]
    public function it_returns_what_it_was_built_with(): void
    {
        $issue = new Issue(UnitId::fromString('u1'), IssueType::MissingTranslation, 'No translation.');
        $stats = new TranslationStats(1, 1, 0, 0.1);

        $document = new TranslatedDocument('<p>Cześć</p>', false, [$issue], $stats);

        self::assertSame('<p>Cześć</p>', $document->html());
        self::assertFalse($document->isComplete());
        self::assertSame([$issue], $document->issues());
        self::assertSame($stats, $document->stats());
    }

    #[Test]
    public function it_rejects_issues_that_are_not_issues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('issues[0] must be an instance of HtmlTranslator\Issue, got string.');

        // @phpstan-ignore argument.type (deliberately wrong input: the runtime check is what is under test)
        new TranslatedDocument('<p></p>', false, ['No translation.'], new TranslationStats(1, 1, 0, 0.1));
    }

    #[Test]
    public function it_rejects_issues_that_are_not_a_list(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('issues must be a list, got key "u1".');

        // @phpstan-ignore argument.type (deliberately wrong input: the runtime check is what is under test)
        new TranslatedDocument('<p></p>', false, ['u1' => new Issue(UnitId::fromString('u1'), IssueType::MissingTranslation, 'x')], new TranslationStats(1, 1, 0, 0.1));
    }
}
