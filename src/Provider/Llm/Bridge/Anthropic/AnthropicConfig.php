<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm\Bridge\Anthropic;

use HtmlTranslator\Exception\InvalidArgumentException;
use SensitiveParameter;
use SensitiveParameterValue;

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

    public const array EFFORT_LEVELS = ['low', 'medium', 'high', 'xhigh', 'max'];

    /** Server-side refusal fallbacks, routed by refusal category ("fallbacks": "default"). */
    public const string REFUSAL_FALLBACKS_BETA = 'server-side-fallback-2026-07-01';

    private const string REDACTED = '[redacted]';

    /** Headers the adapter sets itself. */
    private const array RESERVED_HEADERS = ['x-api-key', 'anthropic-version', 'anthropic-beta', 'content-type', 'accept', 'authorization'];

    /** A header name (RFC 9110 token). */
    private const string HEADER_NAME = '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D';

    /** A header value: visible characters, spaces and tabs, no line breaks or other control characters. */
    private const string HEADER_VALUE = '/^[\x20\x09\x21-\x7E\x80-\xFF]*$/D';

    /** An API key: visible ASCII only, which also rejects the line break of a key read from a file. */
    private const string API_KEY = '/^[\x21-\x7E]+$/D';

    /** Shorter header values are not removed from messages: they are not secrets and would garble the text. */
    private const int MIN_REDACTED_LENGTH = 8;

    private SensitiveParameterValue $apiKey;

    /** @var array<string, SensitiveParameterValue> */
    private array $extraHeaders;

    /**
     * @param string $model e.g. "claude-opus-5-5"; no default, so a model is always chosen on purpose
     * @param string $baseUrl API root without the endpoint, e.g. a gateway in front of the Claude API
     * @param string $apiVersion the anthropic-version header
     * @param int $defaultMaxOutputTokens max_tokens when a request sets no limit; at least 1
     * @param ?string $effort output_config.effort (one of EFFORT_LEVELS); null leaves the model's default
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
        public ?string $effort = null,
        public bool $refusalFallbacks = false,
        #[SensitiveParameter]
        array $extraHeaders = [],
    ) {
        if ('' === trim($model)) {
            throw new InvalidArgumentException('An Anthropic configuration needs a model.');
        }

        if (1 !== preg_match(self::API_KEY, $apiKey)) {
            throw new InvalidArgumentException('The API key must be visible ASCII characters without spaces or line breaks.');
        }

        // The URL is not quoted: it may contain a credential (user info, ?key=).
        $parts = parse_url($baseUrl);
        if (false === $parts || !\in_array($parts['scheme'] ?? null, ['http', 'https'], true) || !isset($parts['host'])) {
            throw new InvalidArgumentException('The Anthropic base URL is not an absolute HTTP(S) URL.');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('The Anthropic base URL must not contain credentials, a query or a fragment.');
        }

        if ('' === trim($apiVersion) || 1 !== preg_match(self::HEADER_VALUE, $apiVersion)) {
            throw new InvalidArgumentException('The Anthropic API version must be a non-empty header value.');
        }

        if ($defaultMaxOutputTokens < 1) {
            throw new InvalidArgumentException(\sprintf('The default output limit must be at least 1, got %d.', $defaultMaxOutputTokens));
        }

        if (null !== $effort && !\in_array($effort, self::EFFORT_LEVELS, true)) {
            throw new InvalidArgumentException(\sprintf('"%s" is not an effort level; expected one of %s.', $effort, implode(', ', self::EFFORT_LEVELS)));
        }

        $headers = [];
        foreach ($extraHeaders as $header => $value) {
            $header = (string) $header;
            if (1 !== preg_match(self::HEADER_NAME, $header)) {
                throw new InvalidArgumentException('An extra header has an invalid name.');
            }

            if (\in_array(strtolower($header), self::RESERVED_HEADERS, true)) {
                throw new InvalidArgumentException(\sprintf('The header "%s" is set by the adapter.', $header));
            }

            if (1 !== preg_match(self::HEADER_VALUE, $value)) {
                throw new InvalidArgumentException(\sprintf('The value of header "%s" contains line breaks or control characters.', $header));
            }

            $headers[$header] = new SensitiveParameterValue($value);
        }

        $this->apiKey = new SensitiveParameterValue($apiKey);
        $this->extraHeaders = $headers;
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
        $headers = array_map($this->reveal(...), $this->extraHeaders) + [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'anthropic-version' => $this->apiVersion,
            'x-api-key' => $this->reveal($this->apiKey),
        ];
        if ($this->refusalFallbacks) {
            $headers['anthropic-beta'] = self::REFUSAL_FALLBACKS_BETA;
        }

        return $headers;
    }

    /**
     * The text without the API key and extra header values (of at least MIN_REDACTED_LENGTH characters), for messages
     * built from vendor or transport errors. Only exact copies are found.
     *
     * @internal for AnthropicChatModel
     */
    public function redact(string $text): string
    {
        $secrets = [...array_values(array_map($this->reveal(...), $this->extraHeaders)), $this->reveal($this->apiKey)];
        $secrets = array_filter($secrets, static fn(string $secret): bool => \strlen($secret) >= self::MIN_REDACTED_LENGTH);

        return str_replace($secrets, self::REDACTED, $text);
    }

    /**
     * @return array{baseUrl: string, model: string, apiKey: string, apiVersion: string, defaultMaxOutputTokens: int, effort: ?string, refusalFallbacks: bool, extraHeaders: array<string, string>}
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'model' => $this->model,
            'apiKey' => self::REDACTED,
            'apiVersion' => $this->apiVersion,
            'defaultMaxOutputTokens' => $this->defaultMaxOutputTokens,
            'effort' => $this->effort,
            'refusalFallbacks' => $this->refusalFallbacks,
            'extraHeaders' => array_map(static fn(): string => self::REDACTED, $this->extraHeaders),
        ];
    }

    private function reveal(SensitiveParameterValue $secret): string
    {
        $value = $secret->getValue();

        return \is_string($value) ? $value : '';
    }
}
