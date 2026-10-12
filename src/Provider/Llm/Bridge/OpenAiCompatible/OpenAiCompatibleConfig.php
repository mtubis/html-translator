<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible;

use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\UntrustedText;
use SensitiveParameter;
use SensitiveParameterValue;

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

    /** Headers the adapter sets itself. */
    private const array RESERVED_HEADERS = ['authorization', 'content-type', 'accept'];

    /** A header name (RFC 9110 token). */
    private const string HEADER_NAME = '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D';

    /** A header value: visible characters, spaces and tabs, no line breaks or other control characters. */
    private const string HEADER_VALUE = '/^[\x20\x09\x21-\x7E\x80-\xFF]*$/D';

    /** An API key: visible ASCII only, which also rejects the line break of a key read from a file. */
    private const string API_KEY = '/^[\x21-\x7E]+$/D';

    /** Shorter header values are not removed from messages: they are not secrets and would garble the text. */
    private const int MIN_REDACTED_LENGTH = 8;

    private ?SensitiveParameterValue $apiKey;

    /** @var array<string, SensitiveParameterValue> */
    private array $extraHeaders;

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

        // The URL is not quoted: it may contain a credential (user info, ?key=).
        $parts = parse_url($baseUrl);
        if (false === $parts || !\in_array($parts['scheme'] ?? null, ['http', 'https'], true) || !isset($parts['host'])) {
            throw new InvalidArgumentException(\sprintf('The base URL of "%s" is not an absolute HTTP(S) URL.', UntrustedText::quote($name)));
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException(\sprintf('The base URL of "%s" must not contain credentials, a query or a fragment.', UntrustedText::quote($name)));
        }

        if ('' === trim($model)) {
            throw new InvalidArgumentException('An OpenAI-compatible configuration needs a model.');
        }

        if (null !== $apiKey && 1 !== preg_match(self::API_KEY, $apiKey)) {
            throw new InvalidArgumentException('The API key must be visible ASCII characters without spaces or line breaks; pass null for a server without authentication.');
        }

        if (!\in_array($maxTokensField, [self::MAX_TOKENS, self::MAX_COMPLETION_TOKENS], true)) {
            throw new InvalidArgumentException(\sprintf('"%s" is not a supported output limit field.', UntrustedText::quote($maxTokensField)));
        }

        $headers = [];
        foreach ($extraHeaders as $header => $value) {
            $header = (string) $header;
            if (1 !== preg_match(self::HEADER_NAME, $header)) {
                throw new InvalidArgumentException('An extra header has an invalid name.');
            }

            if (\in_array(strtolower($header), self::RESERVED_HEADERS, true)) {
                throw new InvalidArgumentException(\sprintf('The header "%s" is set by the adapter.', UntrustedText::quote($header)));
            }

            if (1 !== preg_match(self::HEADER_VALUE, $value)) {
                throw new InvalidArgumentException(\sprintf('The value of header "%s" contains line breaks or control characters.', UntrustedText::quote($header)));
            }

            $headers[$header] = new SensitiveParameterValue($value);
        }

        $this->apiKey = null === $apiKey ? null : new SensitiveParameterValue($apiKey);
        $this->extraHeaders = $headers;
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
        $headers = array_map($this->reveal(...), $this->extraHeaders) + ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($this->apiKey instanceof SensitiveParameterValue) {
            $headers['Authorization'] = 'Bearer ' . $this->reveal($this->apiKey);
        }

        return $headers;
    }

    /**
     * The text without the API key and extra header values (of at least MIN_REDACTED_LENGTH characters), for messages
     * built from vendor or transport errors. Only exact copies are found, not URL-encoded or masked ones.
     *
     * @internal for OpenAiCompatibleChatModel
     */
    public function redact(string $text): string
    {
        $secrets = array_map($this->reveal(...), $this->extraHeaders);
        if ($this->apiKey instanceof SensitiveParameterValue) {
            $secrets[] = $this->reveal($this->apiKey);
        }

        $secrets = array_filter($secrets, static fn(string $secret): bool => \strlen($secret) >= self::MIN_REDACTED_LENGTH);

        return str_replace($secrets, self::REDACTED, $text);
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
            'apiKey' => $this->apiKey instanceof SensitiveParameterValue ? self::REDACTED : null,
            'jsonMode' => $this->jsonMode,
            'maxTokensField' => $this->maxTokensField,
            'extraHeaders' => array_map(static fn(): string => self::REDACTED, $this->extraHeaders),
        ];
    }

    private function reveal(SensitiveParameterValue $secret): string
    {
        $value = $secret->getValue();

        return \is_string($value) ? $value : '';
    }
}
