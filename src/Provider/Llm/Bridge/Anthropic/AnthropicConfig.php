<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm\Bridge\Anthropic;

use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Provider\Llm\Bridge\HttpSecrets;
use SensitiveParameter;

/**
 * Where and how AnthropicChatModel calls the Claude Messages API (docs/providers.md).
 *
 * Timeouts belong to the PSR-18 client. The API key and the values of extra headers (e.g. a gateway token) are only
 * handed to the adapter: they are kept out of var_dump(), print_r(), var_export(), serialize() and validation
 * messages, and the adapter removes them from its error messages.
 */
final readonly class AnthropicConfig
{
    public const string DEFAULT_BASE_URL = 'https://api.anthropic.com/v1';

    public const string DEFAULT_API_VERSION = '2023-06-01';

    /** Fits a non-streaming request; the Messages API requires an output limit on every call. */
    public const int DEFAULT_MAX_OUTPUT_TOKENS = 16000;

    /** Server-side refusal fallbacks, routed by refusal category ("fallbacks": "default"). */
    public const string REFUSAL_FALLBACKS_BETA = 'server-side-fallback-2026-07-01';

    /** Headers the adapter sets itself; Authorization stays free for a gateway in front of the API. */
    private const array RESERVED_HEADERS = ['x-api-key', 'anthropic-version', 'anthropic-beta', 'content-type', 'accept'];

    private HttpSecrets $secrets;

    /**
     * @param string $model e.g. "claude-opus-5-5"; no default, so a model is always chosen on purpose
     * @param string $baseUrl API root without the endpoint, e.g. a gateway in front of the Claude API
     * @param string $apiVersion the anthropic-version header
     * @param int $defaultMaxOutputTokens max_tokens when a request sets no limit; at least 1
     * @param ?AnthropicEffort $effort output_config.effort; null leaves the model's default
     * @param bool $refusalFallbacks let the API answer a refused request with a fallback model (Claude API only)
     * @param array<array-key, string> $extraHeaders treated as secret like the key (PHP turns numeric names into int keys)
     *
     * @throws InvalidArgumentException when a setting is blank or invalid, or a header is one the adapter sets itself
     */
    public function __construct(
        public string $model,
        #[SensitiveParameter]
        string $apiKey,
        public string $baseUrl = self::DEFAULT_BASE_URL,
        public string $apiVersion = self::DEFAULT_API_VERSION,
        public int $defaultMaxOutputTokens = self::DEFAULT_MAX_OUTPUT_TOKENS,
        public ?AnthropicEffort $effort = null,
        public bool $refusalFallbacks = false,
        #[SensitiveParameter]
        array $extraHeaders = [],
    ) {
        if ('' === trim($model)) {
            throw new InvalidArgumentException('An Anthropic configuration needs a model.');
        }

        // Same order of checks as before the secrets moved to HttpSecrets: the key right after the model.
        $secrets = new HttpSecrets($apiKey, $extraHeaders, self::RESERVED_HEADERS);

        HttpSecrets::checkBaseUrl($baseUrl, 'The Anthropic base URL');

        if ('' === trim($apiVersion) || !HttpSecrets::isHeaderValue($apiVersion)) {
            throw new InvalidArgumentException('The Anthropic API version must be a non-empty header value.');
        }

        if ($defaultMaxOutputTokens < 1) {
            throw new InvalidArgumentException(\sprintf('The default output limit must be at least 1, got %d.', $defaultMaxOutputTokens));
        }

        $this->secrets = $secrets;
    }

    public function modelId(): string
    {
        return 'anthropic:' . $this->model;
    }

    /**
     * @internal for AnthropicChatModel
     */
    public function endpoint(): string
    {
        return rtrim($this->baseUrl, '/') . '/messages';
    }

    /**
     * All request headers, including the key.
     *
     * @internal for AnthropicChatModel
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = $this->secrets->extraHeaders() + [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'anthropic-version' => $this->apiVersion,
            'x-api-key' => $this->secrets->apiKey() ?? '',
        ];
        if ($this->refusalFallbacks) {
            $headers['anthropic-beta'] = self::REFUSAL_FALLBACKS_BETA;
        }

        return $headers;
    }

    /**
     * The text without the API key and extra header values, for messages built from vendor or transport errors.
     *
     * @internal for AnthropicChatModel
     */
    public function redact(string $text): string
    {
        return $this->secrets->redact($text);
    }

    /**
     * @return array{baseUrl: string, model: string, apiKey: string, apiVersion: string, defaultMaxOutputTokens: int, effort: ?string, refusalFallbacks: bool, extraHeaders: array<string, string>}
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'model' => $this->model,
            'apiKey' => $this->secrets->maskedApiKey() ?? '',
            'apiVersion' => $this->apiVersion,
            'defaultMaxOutputTokens' => $this->defaultMaxOutputTokens,
            'effort' => $this->effort?->value,
            'refusalFallbacks' => $this->refusalFallbacks,
            'extraHeaders' => $this->secrets->maskedExtraHeaders(),
        ];
    }
}
