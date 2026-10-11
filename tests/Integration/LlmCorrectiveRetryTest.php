<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Integration;

use HtmlTranslator\Domain\Locale;
use HtmlTranslator\HtmlTranslator;
use HtmlTranslator\Provider\Llm\ChatMessage;
use HtmlTranslator\Provider\Llm\ChatRequest;
use HtmlTranslator\Provider\Llm\ChatResponse;
use HtmlTranslator\Provider\Llm\ChatRole;
use HtmlTranslator\Provider\Llm\FinishReason;
use HtmlTranslator\Provider\Llm\LlmTranslationProvider;
use HtmlTranslator\Provider\Llm\Testing\FakeChatModel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End to end without network: the core finds a broken translation, LlmTranslationProvider renders the correction
 * into the next prompt, and the corrected answer lands in the document.
 */
#[CoversNothing]
final class LlmCorrectiveRetryTest extends TestCase
{
    #[Test]
    public function it_sends_the_rejected_answer_and_its_problems_and_uses_the_corrected_one(): void
    {
        $model = FakeChatModel::withResponses(
            $this->response('{"units": [{"id": "u1", "text": "Najpierw przeczytaj <g1>poradnik."}]}'),
            $this->response('{"units": [{"id": "u1", "text": "Najpierw przeczytaj <g1>poradnik</g1>."}]}'),
        );

        $result = new HtmlTranslator(new LlmTranslationProvider($model))
            ->translate('<p>Read the <a href="/guide">guide</a> first.</p>', Locale::fromString('pl'));

        self::assertTrue($result->isComplete());
        self::assertSame([], $result->issues());
        self::assertStringContainsString('<p>Najpierw przeczytaj <a href="/guide">poradnik</a>.</p>', $result->html());

        self::assertCount(2, $model->requests);
        $retry = $model->requests[1];
        $units = $this->units($this->content($retry, ChatRole::User));
        self::assertSame('Najpierw przeczytaj <g1>poradnik.', $units[0]['previous_translation'] ?? null);
        self::assertStringContainsString('</g1>', $units[0]['problems'] ?? '');
        self::assertStringContainsString('previous_translation', $this->content($retry, ChatRole::System));
    }

    private function content(ChatRequest $request, ChatRole $role): string
    {
        $contents = array_values(array_map(
            static fn(ChatMessage $message): string => $message->content,
            array_filter($request->messages, static fn(ChatMessage $message): bool => $role === $message->role),
        ));
        self::assertCount(1, $contents);

        return $contents[0];
    }

    private function response(string $content): ChatResponse
    {
        return new ChatResponse($content, 'fake:model', finishReason: FinishReason::Stop);
    }

    /**
     * @return list<array<string, string>>
     */
    private function units(string $userMessage): array
    {
        $decoded = json_decode($userMessage, true, 4, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('units', $decoded);
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
}
