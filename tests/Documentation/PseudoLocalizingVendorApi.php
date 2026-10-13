<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Documentation;

use HtmlTranslator\Provider\Llm\ChatMessage;
use HtmlTranslator\Provider\Llm\ChatRequest;
use HtmlTranslator\Provider\Llm\ChatRole;
use HtmlTranslator\Tests\Support\PseudoLocalizingChatModel;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use UnexpectedValueException;

/**
 * Stands in for the OpenAI and Anthropic APIs in the documentation snippets: answers a chat request with the
 * pseudo-localization of its units, in the vendor's response format, so the snippets run end to end offline.
 */
final class PseudoLocalizingVendorApi
{
    private function __construct() {}

    public static function client(): MockHttpClient
    {
        $model = PseudoLocalizingChatModel::create();

        return new MockHttpClient(static function (string $method, string $url, array $options) use ($model): MockResponse {
            $request = self::decode($options['body'] ?? null);
            $messages = \is_array($request['messages'] ?? null) ? $request['messages'] : [];
            $last = end($messages);
            $content = \is_array($last) && \is_string($last['content'] ?? null) ? $last['content'] : throw new UnexpectedValueException('The request has no user message.');
            $answer = $model->complete(new ChatRequest([new ChatMessage(ChatRole::User, $content)]))->content;
            $modelName = \is_string($request['model'] ?? null) ? $request['model'] : 'unknown';

            $body = match (true) {
                str_ends_with($url, '/chat/completions') => [
                    'id' => 'chatcmpl-documentation',
                    'object' => 'chat.completion',
                    'model' => $modelName,
                    'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $answer], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 400, 'completion_tokens' => 40, 'total_tokens' => 440],
                ],
                str_ends_with($url, '/messages') => [
                    'id' => 'msg_documentation',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => $modelName,
                    'content' => [['type' => 'text', 'text' => $answer]],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 400, 'output_tokens' => 40],
                ],
                default => throw new UnexpectedValueException(\sprintf('%s %s is not an endpoint of this fake.', $method, $url)),
            };

            return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        });
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decode(mixed $body): array
    {
        if (!\is_string($body)) {
            throw new UnexpectedValueException('The request body is not a string.');
        }

        $decoded = json_decode($body, true, 64, \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : throw new UnexpectedValueException('The request body is not a JSON object.');
    }
}
