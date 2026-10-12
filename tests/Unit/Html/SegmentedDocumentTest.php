<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Html;

use Dom\Element;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Domain\UnitKind;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\UntrustedText;
use HtmlTranslator\Html\AttributeAnchor;
use HtmlTranslator\Html\HtmlLoader;
use HtmlTranslator\Html\LoadedDocument;
use HtmlTranslator\Html\RunAnchor;
use HtmlTranslator\Html\SegmentedDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SegmentedDocument::class)]
#[CoversClass(RunAnchor::class)]
#[UsesClass(AttributeAnchor::class)]
#[UsesClass(HtmlLoader::class)]
#[UsesClass(LoadedDocument::class)]
#[UsesClass(TranslationUnit::class)]
#[UsesClass(UnitId::class)]
#[UsesClass(InvalidArgumentException::class)]
#[UsesClass(UntrustedText::class)]
final class SegmentedDocumentTest extends TestCase
{
    #[Test]
    public function it_rejects_a_unit_without_anchor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"u2"');

        new SegmentedDocument($this->document(), [$this->unit('u1'), $this->unit('u2')], ['u1' => $this->runAnchor()]);
    }

    #[Test]
    public function it_rejects_an_anchor_without_unit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"u9"');

        new SegmentedDocument($this->document(), [$this->unit('u1')], ['u1' => $this->runAnchor(), 'u9' => $this->runAnchor()]);
    }

    #[Test]
    public function it_rejects_an_attribute_anchor_for_a_run_unit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"u1"');

        new SegmentedDocument($this->document(), [$this->unit('u1')], ['u1' => new AttributeAnchor($this->paragraph(), 'title')]);
    }

    #[Test]
    public function it_rejects_a_run_anchor_for_an_attribute_unit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"a1"');

        $unit = TranslationUnit::fromText(UnitId::fromString('a1'), UnitKind::Attribute, 'x');
        new SegmentedDocument($this->document(), [$unit], ['a1' => $this->runAnchor()]);
    }

    #[Test]
    public function it_accepts_matching_units_and_anchors(): void
    {
        $attribute = TranslationUnit::fromText(UnitId::fromString('a1'), UnitKind::Attribute, 'x');
        $segmented = new SegmentedDocument($this->document(), [$this->unit('u1'), $attribute], [
            'u1' => $this->runAnchor(),
            'a1' => new AttributeAnchor($this->paragraph(), 'title'),
        ]);

        self::assertSame([$this->unit('u1')->id->toString(), 'a1'], array_map(static fn(TranslationUnit $unit): string => $unit->id->toString(), $segmented->units()));
    }

    #[Test]
    public function it_has_no_title_or_headings_unless_given(): void
    {
        $segmented = new SegmentedDocument($this->document(), [$this->unit('u1')], ['u1' => $this->runAnchor()]);

        self::assertNull($segmented->title());
        self::assertNull($segmented->sectionHeadingOf(UnitId::fromString('u1')));
    }

    #[Test]
    public function it_returns_the_given_title_and_headings(): void
    {
        $segmented = new SegmentedDocument($this->document(), [$this->unit('u1')], ['u1' => $this->runAnchor()], 'About', ['u1' => 'Team']);

        self::assertSame('About', $segmented->title());
        self::assertSame('Team', $segmented->sectionHeadingOf(UnitId::fromString('u1')));
    }

    #[Test]
    public function it_rejects_a_heading_for_a_unit_it_does_not_have(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"u9"');

        new SegmentedDocument($this->document(), [$this->unit('u1')], ['u1' => $this->runAnchor()], null, ['u9' => 'Team']);
    }

    #[Test]
    public function its_run_anchor_needs_at_least_one_node(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RunAnchor([], []);
    }

    private function document(): LoadedDocument
    {
        return new HtmlLoader()->load('<p>x</p>');
    }

    private function paragraph(): Element
    {
        return $this->document()->root()->firstElementChild ?? self::fail('No paragraph.');
    }

    private function runAnchor(): RunAnchor
    {
        $text = $this->paragraph()->firstChild ?? self::fail('No text.');

        return new RunAnchor([$text], []);
    }

    private function unit(string $id): TranslationUnit
    {
        return TranslationUnit::fromText(UnitId::fromString($id), UnitKind::Block, 'x');
    }
}
