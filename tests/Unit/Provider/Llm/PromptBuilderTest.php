<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Provider\Llm;

use HtmlTranslator\Domain\InlineTag;
use HtmlTranslator\Domain\InlineTagKind;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Domain\LocalePair;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Domain\UnitKind;
use HtmlTranslator\Provider\Correction;
use HtmlTranslator\Provider\Llm\ChatMessage;
use HtmlTranslator\Provider\Llm\ChatRequest;
use HtmlTranslator\Provider\Llm\ChatRole;
use HtmlTranslator\Provider\Llm\PromptBuilder;
use HtmlTranslator\Provider\TranslationBatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The golden files in Fixtures/prompts/ are the reviewed prompt: a change to the wording or the format shows up as
 * a diff there and must come with a new PromptBuilder::VERSION.
 */
#[CoversClass(PromptBuilder::class)]
#[UsesClass(ChatRequest::class)]
#[UsesClass(ChatMessage::class)]
#[UsesClass(TranslationBatch::class)]
#[UsesClass(Correction::class)]
#[UsesClass(InlineTag::class)]
#[UsesClass(Locale::class)]
#[UsesClass(LocalePair::class)]
#[UsesClass(TranslationUnit::class)]
#[UsesClass(UnitId::class)]
final class PromptBuilderTest extends TestCase
{
    private const string GOLDEN_DIRECTORY = __DIR__ . '/Fixtures/prompts';

    /**
     * @return iterable<string, array{TranslationBatch}>
     */
    public static function goldenBatches(): iterable
    {
        $guide = self::unit('u1', 'Read the <g1>installation guide</g1> first.', [InlineTag::of(1, InlineTagKind::Paired)]);
        $contact = self::unit('u2', 'Contact us');

        yield 'plain-batch' => [self::batch([
            self::unit('u1', 'Read the <g1>installation guide</g1> first.<x2/>Then run <x3/>.', [
                InlineTag::of(1, InlineTagKind::Paired),
                InlineTag::of(2, InlineTagKind::Void),
                InlineTag::of(3, InlineTagKind::Opaque),
            ]),
            self::unit('u2', 'Tom &amp; Jerry: a &lt; b, "quoted" and żółć'),
        ])];

        yield 'auto-detected-source' => [new TranslationBatch(
            LocalePair::of(null, Locale::fromString('pl')),
            [self::unit('u1', 'Willkommen auf unserer <g1>Website</g1>', [InlineTag::of(1, InlineTagKind::Paired)])],
        )];

        yield 'plain-text-units' => [self::batch([
            self::unit('t1', 'About us', kind: UnitKind::Title),
            self::unit('a1', 'Company logo', kind: UnitKind::Attribute),
        ])];

        yield 'correction-with-previous-answer' => [self::batch([$guide, $contact], [
            'u1' => new Correction('Najpierw przeczytaj <g1>instrukcję instalacji.', "- Close <g1> with </g1>.\n- Do not leave out words of the source text."),
        ])];

        yield 'correction-without-previous-answer' => [self::batch([self::unit('u1', 'Contact us')], [
            'u1' => Correction::forMissingUnit(),
        ])];
    }

    #[Test]
    #[DataProvider('goldenBatches')]
    public function it_renders_the_reviewed_prompt(TranslationBatch $batch): void
    {
        $name = $this->dataName();
        self::assertIsString($name);

        $request = new PromptBuilder()->build($batch);

        self::assertStringEqualsFile(self::GOLDEN_DIRECTORY . '/' . $name . '.txt', $this->render($request));
    }

    #[Test]
    public function it_sends_one_system_and_one_user_message_and_asks_for_json(): void
    {
        $request = new PromptBuilder()->build(self::batch([self::unit('u1', 'Hello')]));

        self::assertSame([ChatRole::System, ChatRole::User], array_map(static fn(ChatMessage $message): ChatRole => $message->role, $request->messages));
        self::assertTrue($request->jsonResponse);
        self::assertStringContainsString('JSON', $this->content($request, 0));
        self::assertNull($request->temperature);
        self::assertNull($request->maxOutputTokens);
    }

    #[Test]
    public function it_sends_every_unit_text_exactly_as_it_is(): void
    {
        $texts = [
            'u1' => 'Quotes " and \\ backslashes, a/b, <g1>tags</g1>, emoji 🎉 and line' . "\n" . 'breaks',
            'u2' => 'Zwykły tekst',
        ];
        $units = [];
        foreach ($texts as $id => $text) {
            $units[] = self::unit($id, $text, str_contains($text, '<g1>') ? [InlineTag::of(1, InlineTagKind::Paired)] : []);
        }

        $sent = $this->decodeUserMessage(new PromptBuilder()->build(self::batch($units)));

        self::assertSame($texts, array_column($sent, 'text', 'id'));
    }

    #[Test]
    public function it_survives_invalid_utf8_in_a_rejected_answer(): void
    {
        $batch = self::batch([self::unit('u1', 'Hello')], ['u1' => new Correction("Cze\xC5\x9B\xFF\xFE", '- Fix the encoding.')]);

        $sent = $this->decodeUserMessage(new PromptBuilder()->build($batch));

        self::assertSame("Cze\u{015B}\u{FFFD}\u{FFFD}", $sent[0]['previous_translation'] ?? null);
    }

    #[Test]
    public function it_mentions_corrections_only_when_the_batch_has_some(): void
    {
        $builder = new PromptBuilder();

        $plain = $builder->build(self::batch([self::unit('u1', 'Hello')]));
        $retry = $builder->build(self::batch([self::unit('u1', 'Hello')], ['u1' => Correction::forMissingUnit()]));

        self::assertStringNotContainsString('previous_translation', $this->content($plain, 0));
        self::assertStringContainsString('previous_translation', $this->content($retry, 0));
    }

    #[Test]
    public function it_has_a_version_for_the_cache_key(): void
    {
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}\.\d+$/', PromptBuilder::VERSION);
    }

    private function render(ChatRequest $request): string
    {
        return \sprintf("## system\n%s\n\n## user\n%s\n", $this->content($request, 0), $this->content($request, 1));
    }

    private function content(ChatRequest $request, int $index): string
    {
        return ($request->messages[$index] ?? self::fail(\sprintf('The request has no message #%d.', $index)))->content;
    }

    /**
     * @return list<array<string, string>>
     */
    private function decodeUserMessage(ChatRequest $request): array
    {
        $decoded = json_decode($this->content($request, 1), true, 4, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('units', $decoded);
        self::assertCount(1, $decoded);
        self::assertIsList($decoded['units']);

        $units = [];
        foreach ($decoded['units'] as $entry) {
            self::assertIsArray($entry);
            $unit = [];
            foreach ($entry as $key => $value) {
                self::assertIsString($key);
                self::assertIsString($value);
                $unit[$key] = $value;
            }

            $units[] = $unit;
        }

        return $units;
    }

    /**
     * @param list<TranslationUnit> $units
     * @param array<string, Correction> $corrections
     */
    private static function batch(array $units, array $corrections = []): TranslationBatch
    {
        return new TranslationBatch(LocalePair::of(Locale::fromString('en'), Locale::fromString('pl')), $units, $corrections);
    }

    /**
     * @param list<InlineTag> $tags
     */
    private static function unit(string $id, string $text, array $tags = [], UnitKind $kind = UnitKind::Block): TranslationUnit
    {
        return TranslationUnit::fromText(UnitId::fromString($id), $kind, $text, $tags);
    }
}
