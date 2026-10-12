<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Integration;

use HtmlTranslator\Domain\Locale;
use HtmlTranslator\FallbackStrategy;
use HtmlTranslator\HtmlTranslator;
use HtmlTranslator\Provider\Llm\ChatMessage;
use HtmlTranslator\Provider\Llm\ChatRequest;
use HtmlTranslator\Provider\Llm\ChatResponse;
use HtmlTranslator\Provider\Llm\ChatRole;
use HtmlTranslator\Provider\Llm\FinishReason;
use HtmlTranslator\Provider\Llm\LlmTranslationProvider;
use HtmlTranslator\Provider\Llm\Testing\FakeChatModel;
use HtmlTranslator\TranslationOptions;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End to end without network: the page title and section headings reach the model on every call of a document,
 * corrective retries and the text-node fallback included.
 */
#[CoversNothing]
final class LlmContextHintsTest extends TestCase
{
    #[Test]
    public function it_sends_the_title_and_section_headings_on_every_call(): void
    {
        $broken = $this->response('{"units": [{"id": "u3", "text": "Witaj <g1>świecie"}]}');
        $model = FakeChatModel::withResponses(
            $this->response('{"units": [{"id": "u1", "text": "Sklep"}, {"id": "u2", "text": "Plany"}, {"id": "u3", "text": "Witaj <g1>świecie"}]}'),
            $broken,
            $this->response('{"units": [{"id": "u3-1", "text": "Witaj"}, {"id": "u3-2", "text": "świecie"}]}'),
        );

        $result = new HtmlTranslator(new LlmTranslationProvider($model))->translate(
            '<!DOCTYPE html><title>Shop</title><h2>Plans</h2><p>Hello <b>world</b></p>',
            Locale::fromString('pl'),
            new TranslationOptions(fallback: FallbackStrategy::TextNodesOnly, maxCorrectiveRetries: 1),
        );

        self::assertStringContainsString('<p>Witaj <b>świecie</b></p>', $result->html());
        self::assertCount(3, $model->requests, 'first call, corrective retry, text-node fallback');
        foreach ($model->requests as $request) {
            self::assertStringStartsWith('{"context": {"title":"Shop","headings":["Plans"]},', $this->userMessage($request));
        }
    }

    private function userMessage(ChatRequest $request): string
    {
        $user = array_values(array_filter($request->messages, static fn(ChatMessage $message): bool => ChatRole::User === $message->role));
        self::assertCount(1, $user);

        return $user[0]->content;
    }

    private function response(string $content): ChatResponse
    {
        return new ChatResponse($content, 'fake:model', finishReason: FinishReason::Stop);
    }
}
