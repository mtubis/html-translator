<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Integration;

use HtmlTranslator\Domain\Locale;
use HtmlTranslator\FallbackStrategy;
use HtmlTranslator\HtmlTranslator;
use HtmlTranslator\Profile\TranslationProfile;
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
 * End to end without network: the translation profile of the options reaches the model on every call of a document,
 * corrective retries and the text-node fallback included.
 */
#[CoversNothing]
final class LlmTranslationProfileTest extends TestCase
{
    private const string DOMAIN_LINE = 'The texts come from this domain: software and technical documentation.';

    #[Test]
    public function it_sends_the_profile_to_the_model_on_every_call(): void
    {
        $broken = $this->response('{"units": [{"id": "u1", "text": "Witaj <g1>świecie"}]}');
        $model = FakeChatModel::withResponses(
            $broken,
            $broken,
            $this->response('{"units": [{"id": "u1-1", "text": "Witaj"}, {"id": "u1-2", "text": "świecie"}]}'),
        );

        $result = new HtmlTranslator(new LlmTranslationProvider($model))->translate(
            '<p>Hello <b>world</b></p>',
            Locale::fromString('pl'),
            new TranslationOptions(fallback: FallbackStrategy::TextNodesOnly, maxCorrectiveRetries: 1, profile: TranslationProfile::technical()),
        );

        self::assertStringContainsString('<p>Witaj <b>świecie</b></p>', $result->html());
        self::assertCount(3, $model->requests, 'first call, corrective retry, text-node fallback');
        foreach ($model->requests as $request) {
            self::assertStringContainsString(self::DOMAIN_LINE, $this->systemMessage($request));
        }
    }

    #[Test]
    public function it_sends_no_profile_sections_without_a_profile(): void
    {
        $model = FakeChatModel::withResponses($this->response('{"units": [{"id": "u1", "text": "Witaj"}]}'));

        new HtmlTranslator(new LlmTranslationProvider($model))->translate('<p>Hello</p>', Locale::fromString('pl'));

        $system = $this->systemMessage($model->requests[0] ?? self::fail('No request.'));
        foreach (['The texts come from this domain', 'Address the reader', 'Keep technical terms', 'Keep every technical term', 'Translate these terms', 'Do not translate these terms', 'Instructions from the developer'] as $section) {
            self::assertStringNotContainsString($section, $system);
        }
    }

    private function systemMessage(ChatRequest $request): string
    {
        $system = array_values(array_filter($request->messages, static fn(ChatMessage $message): bool => ChatRole::System === $message->role));
        self::assertCount(1, $system);

        return $system[0]->content;
    }

    private function response(string $content): ChatResponse
    {
        return new ChatResponse($content, 'fake:model', finishReason: FinishReason::Stop);
    }
}
