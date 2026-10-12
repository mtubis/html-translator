<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible;

use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\UntrustedText;
use HtmlTranslator\Provider\Llm\Bridge\HttpSecrets;
use SensitiveParameter;

/**
 * Where and how OpenAiCompatibleChatModel calls an OpenAI-style Chat Completions API (docs/providers.md).
 *
 * Timeouts belong to the PSR-18 client: PSR-18 has no per-request timeout. The API key and the values of extra headers
 * (which may be credentials too, e.g. Azure's api-key) are only handed to the adapter: they are kept out of var_dump(),
 * print_r(), var_export(), serialize() and validation messages, and the adapter removes them from its error messages.
 */
final readonly class OpenAiCompatibleConfig
{
    public const string MAX_TOKENS = 'max_tokens';

    /** OpenAI's newer models (o-series, gpt-5) reject max_tokens. */
    public const string MAX_COMPLETION_TOKENS = 'max_completion_tokens';

    private const string REDACTED = '[redacted]';

    private const int MAX_NAME_LENGTH = 100;

    /** Headers the adapter sets itself. */
    private const array RESERVED_HEADERS = ['authorization', 'content-type', 'accept'];

    private HttpSecrets $secrets;

    /**
     * @param string $name prefix of modelId(), e.g. "openai"
     * @param string $baseUrl API root without the endpoint, e.g. "https://api.openai.com/v1"
     * @param ?string $apiKey sent as a Bearer token; null for local servers without authentication
     * @param bool $jsonMode send response_format json_object when a request asks for JSON
     * @param string $maxTokensField the request field for ChatRequest::$maxOutputTokens (MAX_TOKENS or MAX_COMPLETION_TOKENS)
     * @param array<array-key, string> $extraHeaders e.g. OpenAI-Organization; treated as secret like the key (PHP turns
     *                                               numeric names into int keys)
     *
     * @throws InvalidArgumentException when a setting is blank or invalid, or a header is one the adapter sets itself
     */
    public function __construct(
        public string $name,
        public string $baseUrl,
        public string $model,
        #[SensitiveParameter]
        ?string $apiKey = null,
        public bool $jsonMode = true,
        public string $maxTokensField = self::MAX_TOKENS,
        #[SensitiveParameter]
        array $extraHeaders = [],
    ) {
        if ('' === trim($name)) {
            throw new InvalidArgumentException('An OpenAI-compatible configuration needs a name.');
        }

        // The name and the URL appear in every error message of the adapter.
        if (mb_strlen($name) > self::MAX_NAME_LENGTH || 1 === preg_match('/[\p{Cc}\x{2028}\x{2029}]/u', $name) || !mb_check_encoding($name, 'UTF-8')) {
            throw new InvalidArgumentException(\sprintf('The configuration name "%s" must be at most %d characters on one line.', UntrustedText::quote($name), self::MAX_NAME_LENGTH));
        }

        HttpSecrets::checkBaseUrl($baseUrl, \sprintf('The base URL of "%s"', $name));

        if ('' === trim($model)) {
            throw new InvalidArgumentException('An OpenAI-compatible configuration needs a model.');
        }

        if (!\in_array($maxTokensField, [self::MAX_TOKENS, self::MAX_COMPLETION_TOKENS], true)) {
            throw new InvalidArgumentException(\sprintf('"%s" is not a supported output limit field.', UntrustedText::quote($maxTokensField)));
        }

        $this->secrets = new HttpSecrets($apiKey, $extraHeaders, self::RESERVED_HEADERS, '; pass null for a server without authentication');
    }

    /**
     * @throws InvalidArgumentException when the key or model is invalid
     */
    public static function openAi(#[SensitiveParameter] string $apiKey, string $model): self
    {
        return new self('openai', 'https://api.openai.com/v1', $model, $apiKey, maxTokensField: self::MAX_COMPLETION_TOKENS);
    }

    /**
     * Gemini's OpenAI-compatible endpoint.
     *
     * @throws InvalidArgumentException when the key or model is invalid
     */
    public static function gemini(#[SensitiveParameter] string $apiKey, string $model): self
    {
        return new self('gemini', 'https://generativelanguage.googleapis.com/v1beta/openai', $model, $apiKey);
    }

    /**
     * @throws InvalidArgumentException when the model is blank or the URL invalid
     */
    public static function ollama(string $model, string $baseUrl = 'http://localhost:11434/v1'): self
    {
        return new self('ollama', $baseUrl, $model);
    }

    /**
     * @throws InvalidArgumentException when the model is blank, the URL or the key invalid
     */
    public static function vllm(string $model, string $baseUrl = 'http://localhost:8000/v1', #[SensitiveParameter] ?string $apiKey = null): self
    {
        return new self('vllm', $baseUrl, $model, $apiKey);
    }

    /**
     * LM Studio only offers structured output with a JSON schema, so JSON mode is off.
     *
     * @throws InvalidArgumentException when the model is blank or the URL invalid
     */
    public static function lmStudio(string $model, string $baseUrl = 'http://localhost:1234/v1'): self
    {
        return new self('lmstudio', $baseUrl, $model, jsonMode: false);
    }

    public function modelId(): string
    {
        return $this->name . ':' . $this->model;
    }

    /**
     * @internal for OpenAiCompatibleChatModel
     */
    public function endpoint(): string
    {
        return rtrim($this->baseUrl, '/') . '/chat/completions';
    }

    /**
     * All request headers, including the credentials.
     *
     * @internal for OpenAiCompatibleChatModel
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = $this->secrets->extraHeaders() + ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        $apiKey = $this->secrets->apiKey();
        if (null !== $apiKey) {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        return $headers;
    }

    /**
     * The text without the API key and extra header values, for messages built from vendor or transport errors.
     *
     * @internal for OpenAiCompatibleChatModel
     */
    public function redact(string $text): string
    {
        return $this->secrets->redact($text);
    }

    /**
     * @return array{name: string, baseUrl: string, model: string, apiKey: ?string, jsonMode: bool, maxTokensField: string, extraHeaders: array<string, string>}
     */
    public function __debugInfo(): array
    {
        return [
            'name' => $this->name,
            'baseUrl' => $this->baseUrl,
            'model' => $this->model,
            'apiKey' => $this->secrets->hasApiKey() ? self::REDACTED : null,
            'jsonMode' => $this->jsonMode,
            'maxTokensField' => $this->maxTokensField,
            'extraHeaders' => $this->secrets->maskedExtraHeaders(),
        ];
    }
}
