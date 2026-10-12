<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm\Bridge\Anthropic;

use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Provider\Llm\ChatMessage;
use HtmlTranslator\Provider\Llm\ChatModelInterface;
use HtmlTranslator\Provider\Llm\ChatRequest;
use HtmlTranslator\Provider\Llm\ChatResponse;
use HtmlTranslator\Provider\Llm\ChatRole;
use HtmlTranslator\Provider\Llm\FinishReason;
use HtmlTranslator\Provider\Llm\JsonSchema;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

/**
 * ChatModelInterface for the Claude Messages API (POST {baseUrl}/messages, anthropic-version 2023-06-01, API shape as
 * of 2026-10) on any PSR-18 client (ADR-0002).
 *
 * A JSON answer with a schema is asked for as structured output (output_config.format), which guarantees valid JSON;
 * current models reject assistant prefill (ADR-0014). Only text blocks make up the answer: thinking blocks, which
 * current models return even when the thinking is not shown, are skipped. A refusal (stop_reason "refusal") becomes
 * FinishReason::Refusal. No transport retries yet (0.4).
 */
final readonly class AnthropicChatModel implements ChatModelInterface
{
    /** The Messages API accepts temperatures from 0 to 1; ChatRequest allows up to 2. */
    private const float MAX_TEMPERATURE = 1.0;

    /** Vendor error messages are cut to this many characters. */
    private const int VENDOR_MESSAGE_LENGTH = 200;

    /**
     * Invalid UTF-8 in a message is replaced rather than failing the call (the validator judges the answer anyway);
     * a temperature of 0.0 stays a number with a fraction.
     */
    private const int JSON_FLAGS = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR;

    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private AnthropicConfig $config,
    ) {}

    public function modelId(): string
    {
        return $this->config->modelId();
    }

    /**
     * @throws InvalidArgumentException when the temperature is above 1, which the Messages API does not accept
     */
    public function complete(ChatRequest $request): ChatResponse
    {
        $httpRequest = $this->requestFactory->createRequest('POST', $this->config->endpoint())
            ->withBody($this->streamFactory->createStream(json_encode($this->body($request), self::JSON_FLAGS)));
        foreach ($this->config->headers() as $name => $value) {
            $httpRequest = $httpRequest->withHeader($name, $value);
        }

        try {
            $response = $this->client->sendRequest($httpRequest);
        } catch (ClientExceptionInterface $exception) {
            throw $this->transportError($exception);
        }

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        if ($status < 200 || $status > 299) {
            throw $this->httpError($status, $body);
        }

        return $this->answer($body);
    }

    /**
     * @throws InvalidArgumentException when the temperature is above 1
     *
     * @return array<string, mixed>
     */
    private function body(ChatRequest $request): array
    {
        // Rejected rather than clamped, so a setting is never silently changed.
        if (null !== $request->temperature && $request->temperature > self::MAX_TEMPERATURE) {
            throw new InvalidArgumentException(\sprintf('The Messages API accepts temperatures from 0 to 1, got %s.', var_export($request->temperature, true)));
        }

        $body = ['model' => $this->config->model, 'max_tokens' => $request->maxOutputTokens ?? $this->config->defaultMaxOutputTokens];

        $messages = $request->messages;
        // ChatRequest allows a system message only first; the Messages API keeps it outside the conversation.
        if (ChatRole::System === ($messages[0] ?? null)?->role) {
            $body['system'] = array_shift($messages)->content;
        }

        $body['messages'] = array_map(static fn(ChatMessage $message): array => ['role' => $message->role->value, 'content' => $message->content], $messages);

        if (null !== $request->temperature) {
            $body['temperature'] = $request->temperature;
        }

        $outputConfig = [];
        if (null !== $this->config->effort) {
            $outputConfig['effort'] = $this->config->effort;
        }

        // Without a schema there is nothing to enforce: the prompt asks for JSON and the caller's parser copes.
        if ($request->jsonResponse && $request->jsonSchema instanceof JsonSchema) {
            $outputConfig['format'] = ['type' => 'json_schema', 'schema' => json_decode($request->jsonSchema->json, false, 512, \JSON_THROW_ON_ERROR)];
        }

        if ([] !== $outputConfig) {
            $body['output_config'] = $outputConfig;
        }

        if ($this->config->refusalFallbacks) {
            $body['fallbacks'] = 'default';
        }

        return $body;
    }

    /**
     * @throws ProviderException
     */
    private function answer(string $body): ChatResponse
    {
        $data = $this->decode($body);
        $blocks = $data['content'] ?? null;
        if (!\is_array($blocks) || !array_is_list($blocks)) {
            throw new ProviderException('anthropic answered without a content list.');
        }

        $content = '';
        foreach ($blocks as $block) {
            if (!\is_array($block) || 'text' !== ($block['type'] ?? null)) {
                continue;
            }

            $text = $block['text'] ?? null;
            if (!\is_string($text)) {
                throw new ProviderException('anthropic answered with a text block without text.');
            }

            $content .= $text;
        }

        $model = $data['model'] ?? null;
        $usage = \is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return new ChatResponse(
            $content,
            \is_string($model) && '' !== trim($model) ? $model : $this->config->model,
            $this->tokens($usage['input_tokens'] ?? null),
            $this->tokens($usage['output_tokens'] ?? null),
            $this->finishReason($data['stop_reason'] ?? null),
        );
    }

    private function finishReason(mixed $reason): ?FinishReason
    {
        return match ($reason) {
            null => null,
            'end_turn', 'stop_sequence' => FinishReason::Stop,
            'max_tokens', 'model_context_window_exceeded' => FinishReason::Length,
            'refusal' => FinishReason::Refusal,
            default => FinishReason::Other,
        };
    }

    private function tokens(mixed $count): ?int
    {
        return \is_int($count) && $count >= 0 ? $count : null;
    }

    /**
     * Error bodies are {"type": "error", "error": {"type", "message"}, "request_id"}.
     */
    private function httpError(int $status, string $body): ProviderException
    {
        // Proxy pages and other non-JSON bodies are not quoted: they may be large and say nothing useful.
        $data = json_decode($body, true);
        $error = \is_array($data) && \is_array($data['error'] ?? null) ? $data['error'] : [];

        $vendorCode = \is_string($error['type'] ?? null) && '' !== $error['type'] ? $error['type'] : null;
        $vendorMessage = \is_string($error['message'] ?? null) ? $this->config->redact($error['message']) : '';
        if (mb_strlen($vendorMessage) > self::VENDOR_MESSAGE_LENGTH) {
            $vendorMessage = mb_substr($vendorMessage, 0, self::VENDOR_MESSAGE_LENGTH - 1) . '…';
        }

        $message = \sprintf('anthropic returned HTTP %d', $status);

        return ProviderException::fromHttpResponse($status, '' === $vendorMessage ? $message . '.' : $message . ': ' . $vendorMessage, $vendorCode);
    }

    /**
     * Keeps the client's exception as the cause unless a message in its chain contains a credential; then a redacted
     * copy of its message replaces the whole chain. A kept PSR-18 exception may still hold the request (getRequest()),
     * including its x-api-key header.
     */
    private function transportError(ClientExceptionInterface $exception): ProviderException
    {
        $message = $this->config->redact($exception->getMessage());
        $cause = $exception;
        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($this->config->redact($current->getMessage()) !== $current->getMessage()) {
                $cause = new ProviderException($message);

                break;
            }
        }

        return new ProviderException(\sprintf('Could not reach anthropic at %s: %s', $this->config->endpoint(), $message), 0, $cause);
    }

    /**
     * @throws ProviderException
     * @return array<mixed>
     */
    private function decode(string $body): array
    {
        try {
            $data = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ProviderException(\sprintf('anthropic answered with a body that is not valid JSON: %s.', $exception->getMessage()), 0, $exception);
        }

        if (!\is_array($data) || array_is_list($data)) {
            throw new ProviderException('anthropic answered with JSON that is not an object.');
        }

        return $data;
    }
}
