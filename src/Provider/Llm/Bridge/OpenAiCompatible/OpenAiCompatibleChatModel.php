<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible;

use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Exception\UntrustedText;
use HtmlTranslator\Provider\Llm\Bridge\Retrier;
use HtmlTranslator\Provider\Llm\Bridge\TransportFailure;
use HtmlTranslator\Provider\Llm\ChatMessage;
use HtmlTranslator\Provider\Llm\ChatModelInterface;
use HtmlTranslator\Provider\Llm\ChatRequest;
use HtmlTranslator\Provider\Llm\ChatResponse;
use HtmlTranslator\Provider\Llm\FinishReason;
use JsonException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SensitiveParameter;

/**
 * ChatModelInterface for OpenAI-style Chat Completions APIs (POST {baseUrl}/chat/completions, API shape as of 2026-10):
 * OpenAI, Gemini's OpenAI-compatible endpoint, Ollama, vLLM, LM Studio and others, on any PSR-18 client (ADR-0002).
 *
 * A refusal (message.refusal, sent with finish_reason "stop") becomes FinishReason::Refusal with the refusal as content,
 * so LlmTranslationProvider reports it instead of retrying. No transport retries yet (0.4).
 */
final readonly class OpenAiCompatibleChatModel implements ChatModelInterface
{
    /** Vendor error messages are cut to this many characters. */
    private const int VENDOR_MESSAGE_LENGTH = 200;

    /**
     * Invalid UTF-8 in a message is replaced rather than failing the call (the validator judges the answer anyway);
     * a temperature of 0.0 stays a number with a fraction for servers that type-check it.
     */
    private const int JSON_FLAGS = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR;

    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private OpenAiCompatibleConfig $config,
        private Retrier $retrier = new Retrier(),
    ) {}

    public function modelId(): string
    {
        return $this->config->modelId();
    }

    public function complete(ChatRequest $request): ChatResponse
    {
        $httpRequest = $this->requestFactory->createRequest('POST', $this->config->endpoint())
            ->withBody($this->streamFactory->createStream(json_encode($this->body($request), self::JSON_FLAGS)));
        foreach ($this->config->headers() as $name => $value) {
            $httpRequest = $httpRequest->withHeader($name, $value);
        }

        try {
            $retried = $this->retrier->send($this->client, $httpRequest);
        } catch (TransportFailure $failure) {
            throw $this->transportError($failure);
        }

        $response = $retried->response;
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        if ($status < 200 || $status > 299) {
            throw $this->httpError($status, $body, $retried->attempts);
        }

        return $this->answer($body);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ChatRequest $request): array
    {
        $body = [
            'model' => $this->config->model,
            'messages' => array_map(static fn(ChatMessage $message): array => ['role' => $message->role->value, 'content' => $message->content], $request->messages),
        ];

        if (null !== $request->temperature) {
            $body['temperature'] = $request->temperature;
        }

        if (null !== $request->maxOutputTokens) {
            $body[$this->config->maxTokensField] = $request->maxOutputTokens;
        }

        if ($request->jsonResponse && $this->config->jsonMode) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        return $body;
    }

    /**
     * @throws ProviderException
     */
    private function answer(string $body): ChatResponse
    {
        $data = $this->decode($body);
        $choice = \is_array($data['choices'] ?? null) ? ($data['choices'][0] ?? null) : null;
        if (!\is_array($choice)) {
            throw new ProviderException(\sprintf('%s answered without a choice.', $this->config->name));
        }

        // Some servers drop the message of a blocked answer and only send the finish reason.
        $message = $choice['message'] ?? null;
        if (!\is_array($message)) {
            $message = \is_string($choice['finish_reason'] ?? null) ? [] : throw new ProviderException(\sprintf('%s answered without a message.', $this->config->name));
        }

        $refusal = $message['refusal'] ?? null;
        $content = $message['content'] ?? null;
        if (\is_string($refusal) && '' !== $refusal) {
            [$content, $finishReason] = [$refusal, FinishReason::Refusal];
        } elseif (null === $content || \is_string($content)) {
            $finishReason = $this->finishReason($choice['finish_reason'] ?? null);
        } else {
            throw new ProviderException(\sprintf('%s answered with content that is not text.', $this->config->name));
        }

        $model = $data['model'] ?? null;
        $usage = \is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return new ChatResponse(
            $content ?? '',
            \is_string($model) && '' !== trim($model) ? $model : $this->config->model,
            $this->tokens($usage['prompt_tokens'] ?? null),
            $this->tokens($usage['completion_tokens'] ?? null),
            $finishReason,
        );
    }

    private function finishReason(mixed $reason): ?FinishReason
    {
        return match ($reason) {
            null => null,
            'stop' => FinishReason::Stop,
            'length' => FinishReason::Length,
            'content_filter' => FinishReason::ContentFilter,
            default => FinishReason::Other,
        };
    }

    private function tokens(mixed $count): ?int
    {
        return \is_int($count) && $count >= 0 ? $count : null;
    }

    /**
     * Error bodies are {"error": {"message", "type", "code"}}, or a list of such objects (Gemini), with
     * Gemini's "status" instead of a string code.
     */
    private function httpError(int $status, #[SensitiveParameter] string $body, int $attempts): ProviderException
    {
        // Proxy pages and other non-JSON bodies are not quoted: they may be large and say nothing useful.
        $data = json_decode($body, true);
        $data = \is_array($data) ? $data : [];
        $first = $data[0] ?? null;
        $error = $data['error'] ?? (\is_array($first) ? $first['error'] ?? null : null);
        $error = \is_array($error) ? $error : [];

        $vendorCode = null;
        foreach (['code', 'type', 'status'] as $field) {
            if (\is_string($error[$field] ?? null) && '' !== $error[$field]) {
                $vendorCode = $error[$field];

                break;
            }
        }

        $vendorMessage = \is_string($error['message'] ?? null) ? UntrustedText::quote($this->config->redact($error['message']), self::VENDOR_MESSAGE_LENGTH) : '';

        $message = \sprintf('%s returned HTTP %d', $this->config->name, $status) . $this->afterAttempts($attempts);

        return ProviderException::fromHttpResponse($status, '' === $vendorMessage ? $message . '.' : $message . ': ' . $vendorMessage, $vendorCode);
    }

    /**
     * Never keeps the client's exception: PSR-18 request and network exceptions hold the request with its credential
     * headers, and any exception thrown inside sendRequest() recorded the request among its trace arguments. The cause
     * is a ProviderException naming the client's exception type and its redacted message; the parameter is sensitive,
     * so the client's exception does not reach the traces of the new exceptions either.
     */
    private function transportError(#[SensitiveParameter] TransportFailure $failure): ProviderException
    {
        $message = UntrustedText::quote($this->config->redact($failure->getMessage()));

        return new ProviderException(\sprintf('Could not reach %s at %s%s: %s', $this->config->name, $this->config->endpoint(), $this->afterAttempts($failure->attempts), $message), 0, new ProviderException(\sprintf('%s: %s', get_debug_type($failure->getPrevious()), $message)));
    }

    /**
     * @throws ProviderException
     * @return array<mixed>
     */
    private function decode(#[SensitiveParameter] string $body): array
    {
        try {
            $data = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ProviderException(\sprintf('%s answered with a body that is not valid JSON: %s.', $this->config->name, $exception->getMessage()), 0, $exception);
        }

        if (!\is_array($data)) {
            throw new ProviderException(\sprintf('%s answered with JSON that is not an object.', $this->config->name));
        }

        return $data;
    }

    /**
     * " after N attempts" when the Retrier tried more than once, so the message says the failure was not a one-off.
     */
    private function afterAttempts(int $attempts): string
    {
        return $attempts > 1 ? \sprintf(' after %d attempts', $attempts) : '';
    }
}
