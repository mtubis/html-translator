<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Provider\Llm;

use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Domain\LocalePair;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Domain\UnitKind;
use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Provider\BatchResult;
use HtmlTranslator\Provider\Llm\ChatResponse;
use HtmlTranslator\Provider\Llm\FinishReason;
use HtmlTranslator\Provider\Llm\ResponseParser;
use HtmlTranslator\Provider\TranslationBatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResponseParser::class)]
#[UsesClass(ChatResponse::class)]
#[UsesClass(BatchResult::class)]
#[UsesClass(TranslationBatch::class)]
#[UsesClass(ProviderException::class)]
#[UsesClass(Locale::class)]
#[UsesClass(LocalePair::class)]
#[UsesClass(TranslationUnit::class)]
#[UsesClass(UnitId::class)]
final class ResponseParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array<string, string>, list<string>}> answer, translations, failed ids
     */
    public static function answers(): iterable
    {
        yield 'clean JSON' => [
            '{"units": [{"id": "u1", "text": "Jeden"}, {"id": "u2", "text": "Dwa"}]}',
            ['u1' => 'Jeden', 'u2' => 'Dwa'],
            [],
        ];

        yield 'unit text is kept exactly as written' => [
            '{"units": [{"id": "u1", "text": "Tom &amp; <g1>Jerry</g1>\na < b ł"}]}',
            ['u1' => "Tom &amp; <g1>Jerry</g1>\na < b ł"],
            [],
        ];

        yield 'whitespace around a text is removed' => [
            '{"units": [{"id": "u1", "text": " Jeden\n"}, {"id": "u2", "text": "\t Dwa"}]}',
            ['u1' => 'Jeden', 'u2' => "\u{00A0}Dwa"],
            [],
        ];

        yield 'code fence with language' => [
            "```json\n{\"units\": [{\"id\": \"u1\", \"text\": \"Jeden\"}]}\n```",
            ['u1' => 'Jeden'],
            [],
        ];

        yield 'code fence without language' => [
            "```\n{\"units\": [{\"id\": \"u1\", \"text\": \"Jeden\"}]}\n```\n",
            ['u1' => 'Jeden'],
            [],
        ];

        yield 'prose around the JSON' => [
            "Here is the translation:\n{\"units\": [{\"id\": \"u1\", \"text\": \"Jeden\"}]}\nLet me know if you need anything else.",
            ['u1' => 'Jeden'],
            [],
        ];

        yield 'prose right after the JSON' => [
            '{"units": [{"id": "u1", "text": "Jeden"}]}. Done.',
            ['u1' => 'Jeden'],
            [],
        ];

        yield 'braces in prose after the JSON' => [
            "{\"units\": [{\"id\": \"u1\", \"text\": \"Cześć {name}\"}]}\nNote: I kept {name} unchanged.",
            ['u1' => 'Cześć {name}'],
            [],
        ];

        yield 'brackets in prose before the JSON' => [
            "Placeholders like {name} kept. Translation [en → pl] (see [below:\n```json\n{\"units\": [{\"id\": \"u1\", \"text\": \"Jeden\"}]}\n```",
            ['u1' => 'Jeden'],
            [],
        ];

        yield 'a JSON object without units before the answer' => [
            "Settings: {\"temperature\": 0}\n{\"units\": [{\"id\": \"u1\", \"text\": \"Jeden\"}]}",
            ['u1' => 'Jeden'],
            [],
        ];

        yield 'two code fences: the first answer wins' => [
            "```json\n{\"units\": [{\"id\": \"u1\", \"text\": \"Jeden\"}]}\n```\nOr:\n```json\n{\"units\": [{\"id\": \"u1\", \"text\": \"Raz\"}]}\n```",
            ['u1' => 'Jeden'],
            [],
        ];

        yield 'trailing comma before a line break' => [
            "{\"units\": [\n{\"id\": \"u1\", \"text\": \"Jeden\"},\n]}",
            ['u1' => 'Jeden'],
            [],
        ];

        yield 'trailing comma in the list' => [
            '{"units": [{"id": "u1", "text": "Jeden"}, {"id": "u2", "text": "Dwa"},]}',
            ['u1' => 'Jeden', 'u2' => 'Dwa'],
            [],
        ];

        yield 'prose and two trailing commas' => [
            'Answer: {"units": [{"id": "u1", "text": "Jeden",},]}',
            ['u1' => 'Jeden'],
            [],
        ];

        yield 'trailing comma in a unit' => [
            '{"units": [{"id": "u1", "text": "Jeden",}]}',
            ['u1' => 'Jeden'],
            [],
        ];

        yield 'commas and brackets inside a text are not touched' => [
            '{"units": [{"id": "u1", "text": "a, ] b,} \"c,]\""},]}',
            ['u1' => 'a, ] b,} "c,]"'],
            [],
        ];

        yield 'top-level list instead of an object' => [
            '[{"id": "u1", "text": "Jeden"}]',
            ['u1' => 'Jeden'],
            [],
        ];

        yield 'unknown ids are ignored' => [
            '{"units": [{"id": "u1", "text": "Jeden"}, {"id": "u9", "text": "Dziewięć"}]}',
            ['u1' => 'Jeden'],
            [],
        ];

        yield 'missing ids are left out' => [
            '{"units": [{"id": "u2", "text": "Dwa"}]}',
            ['u2' => 'Dwa'],
            [],
        ];

        yield 'empty list' => ['{"units": []}', [], []];

        yield 'identical duplicate is harmless' => [
            '{"units": [{"id": "u1", "text": "Jeden"}, {"id": "u1", "text": "Jeden"}]}',
            ['u1' => 'Jeden'],
            [],
        ];

        yield 'conflicting duplicate fails the unit' => [
            '{"units": [{"id": "u1", "text": "Jeden"}, {"id": "u2", "text": "Dwa"}, {"id": "u1", "text": "Trzy"}]}',
            ['u2' => 'Dwa'],
            ['u1'],
        ];

        yield 'identical non-string duplicates are reported as not a string' => [
            '{"units": [{"id": "u1", "text": {"a": 1}}, {"id": "u1", "text": {"a": 1}}]}',
            [],
            ['u1'],
        ];

        yield 'non-string texts fail their unit' => [
            '{"units": [{"id": "u1", "text": 1}, {"id": "u2", "text": null}, {"id": "u3", "text": ["Trzy"]}]}',
            [],
            ['u1', 'u2', 'u3'],
        ];

        yield 'a unit without text fails' => [
            '{"units": [{"id": "u1"}, {"id": "u2", "text": "Dwa"}]}',
            ['u2' => 'Dwa'],
            ['u1'],
        ];

        yield 'an empty text is passed on for validation' => [
            '{"units": [{"id": "u1", "text": ""}]}',
            ['u1' => ''],
            [],
        ];

        yield 'entries that cannot be attributed are ignored' => [
            '{"units": ["Jeden", 2, null, {"text": "Dwa"}, {"id": 3, "text": "Trzy"}, {"id": "u1", "text": "Jeden"}]}',
            ['u1' => 'Jeden'],
            [],
        ];
    }

    /**
     * @param array<string, string> $translations
     * @param list<string> $failed
     */
    #[Test]
    #[DataProvider('answers')]
    public function it_reads_the_translations_from_the_answer(string $answer, array $translations, array $failed): void
    {
        $result = new ResponseParser()->parse($this->response($answer), $this->batch());

        self::assertSame($translations, $result->translations);
        self::assertSame($failed, array_keys($result->failures));
        self::assertSame([], $result->permanentFailures);
    }

    #[Test]
    public function it_explains_why_a_unit_failed(): void
    {
        $result = new ResponseParser()->parse(
            $this->response('{"units": [{"id": "u1", "text": "Jeden"}, {"id": "u1", "text": "Raz"}, {"id": "u2", "text": 2}]}'),
            $this->batch(),
        );

        self::assertStringContainsString('more than once', $result->failures['u1'] ?? '');
        self::assertStringContainsString('not a string', $result->failures['u2'] ?? '');
    }

    #[Test]
    public function it_reports_an_identical_non_string_duplicate_as_not_a_string(): void
    {
        $result = new ResponseParser()->parse($this->response('{"units": [{"id": "u1", "text": {"a": 1}}, {"id": "u1", "text": {"a": 1}}]}'), $this->batch());

        self::assertStringContainsString('not a string', $result->failures['u1'] ?? '');
    }

    #[Test]
    public function it_accepts_a_complete_answer_that_hit_the_token_limit(): void
    {
        $result = new ResponseParser()->parse($this->response('{"units": [{"id": "u1", "text": "Jeden"}]}', FinishReason::Length), $this->batch());

        self::assertSame(['u1' => 'Jeden'], $result->translations);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableAnswers(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => [" \n "];
        yield 'prose only' => ['Sorry, I cannot help with that.'];
        yield 'opening bracket only' => ['Here it is: {"units": ['];
        yield 'closing bracket before the opening one' => ['} and then {'];
        yield 'invalid JSON' => ['{"units": [{"id": "u1", "text": "Jeden"]}'];
        yield 'unclosed, ending in whitespace' => ["{\"units\": [ \n"];
        yield 'two trailing commas' => ['{"units": [{"id": "u1", "text": "Jeden"},,]}'];
        yield 'object without units' => ['{"translations": [{"id": "u1", "text": "Jeden"}]}'];
        yield 'units is not a list' => ['{"units": {"u1": "Jeden"}}'];
        yield 'scalar' => ['"Jeden"'];
    }

    #[Test]
    #[DataProvider('unusableAnswers')]
    public function it_rejects_an_unusable_answer_for_the_whole_batch(string $answer): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionCode(0);

        new ResponseParser()->parse($this->response($answer), $this->batch());
    }

    #[Test]
    public function it_reports_a_truncated_answer_as_cut_off(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/cut off.*fewer units/i');
        $this->expectExceptionCode(0);

        new ResponseParser()->parse($this->response('{"units": [{"id": "u1", "text": "Jed', FinishReason::Length), $this->batch());
    }

    #[Test]
    public function it_keeps_the_parse_error_behind_the_cut_off_error(): void
    {
        try {
            new ResponseParser()->parse($this->response('{"result": "Jeden"}', FinishReason::Length), $this->batch());
            self::fail('Expected a ProviderException.');
        } catch (ProviderException $exception) {
            self::assertStringContainsString('cut off', $exception->getMessage());
            self::assertInstanceOf(ProviderException::class, $exception->getPrevious());
            self::assertStringContainsString('no "units" list', $exception->getPrevious()->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathologicalAnswers(): iterable
    {
        $size = 200_000;

        yield 'unterminated string of escapes' => ['{' . str_repeat('"\\', $size) . '}'];
        yield 'escaped quotes in an open string' => ['{"' . str_repeat('\\"', $size) . '}'];
        yield 'unclosed brackets' => [str_repeat('[', $size)];
        yield 'many commas' => ['[' . str_repeat(',', $size) . ']'];
        yield 'bracketed prose' => [str_repeat('{name} ', intdiv($size, 7))];
    }

    /**
     * Each candidate is scanned once, so the time grows linearly with the answer; a backtracking scanner needed
     * seconds for these inputs.
     */
    #[Test]
    #[DataProvider('pathologicalAnswers')]
    public function it_rejects_pathological_answers_quickly(string $answer): void
    {
        $started = hrtime(true);

        try {
            new ResponseParser()->parse($this->response($answer), $this->batch());
            self::fail('Expected a ProviderException.');
        } catch (ProviderException) {
            self::assertLessThan(1.0, (hrtime(true) - $started) / 1e9);
        }
    }

    #[Test]
    public function it_tells_an_answer_without_json_from_invalid_json(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('contains no complete JSON value');

        new ResponseParser()->parse($this->response('Here it is: {"units": ['), $this->batch());
    }

    #[Test]
    public function it_reports_invalid_json_with_the_json_error(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('not valid JSON: Syntax error');

        new ResponseParser()->parse($this->response('{"units": [{"id": "u1" "text": "Jeden"}]}'), $this->batch());
    }

    #[Test]
    public function it_looks_for_the_answer_behind_at_most_seven_bracketed_phrases(): void
    {
        $answer = '{"units": [{"id": "u1", "text": "Jeden"}]}';

        $result = new ResponseParser()->parse($this->response(str_repeat('{x} ', 7) . $answer), $this->batch());
        self::assertSame(['u1' => 'Jeden'], $result->translations);

        $this->expectException(ProviderException::class);
        new ResponseParser()->parse($this->response(str_repeat('{x} ', 8) . $answer), $this->batch());
    }

    #[Test]
    public function it_keeps_the_answer_out_of_the_error_message(): void
    {
        try {
            new ResponseParser()->parse($this->response('Secret customer data: {"units": [oops]}'), $this->batch());
            self::fail('Expected a ProviderException.');
        } catch (ProviderException $exception) {
            self::assertStringNotContainsString('Secret customer data', $exception->getMessage());
            self::assertStringNotContainsString('oops', $exception->getMessage());
        }
    }

    private function response(string $content, ?FinishReason $finishReason = FinishReason::Stop): ChatResponse
    {
        return new ChatResponse($content, 'fake:model', finishReason: $finishReason);
    }

    private function batch(): TranslationBatch
    {
        $units = array_map(
            static fn(string $id): TranslationUnit => TranslationUnit::fromText(UnitId::fromString($id), UnitKind::Block, 'Text of ' . $id),
            ['u1', 'u2', 'u3'],
        );

        return new TranslationBatch(LocalePair::of(Locale::fromString('en'), Locale::fromString('pl')), $units);
    }
}
