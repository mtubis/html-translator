<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm\Bridge;

use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\UntrustedText;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * The credentials of a chat model configuration (the API key and extra headers, which may be credentials too, e.g. a
 * gateway token) and the base URL check, shared by the adapters' configs so their security rules cannot drift.
 *
 * Secrets are kept as SensitiveParameterValue: they stay out of var_dump(), print_r(), var_export() and recorded
 * trace arguments, and serialize() throws.
 *
 * @internal for the configs in Provider\Llm\Bridge
 */
final readonly class HttpSecrets
{
    private const string REDACTED = '[redacted]';

    /** A header name (RFC 9110 token). */
    private const string HEADER_NAME = '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D';

    /** A header value: visible characters, spaces and tabs, no line breaks or other control characters. */
    private const string HEADER_VALUE = '/^[\x20\x09\x21-\x7E\x80-\xFF]*$/D';

    /** An API key: visible ASCII only, which also rejects the line break of a key read from a file. */
    private const string API_KEY = '/^[\x21-\x7E]+$/D';

    /** Shorter header values are not removed from messages: they are rarely secrets and would garble the text. */
    private const int MIN_REDACTED_LENGTH = 8;

    private ?SensitiveParameterValue $apiKey;

    /** @var array<string, SensitiveParameterValue> */
    private array $extraHeaders;

    /**
     * @param array<array-key, string> $extraHeaders PHP turns numeric names into int keys
     * @param list<string> $reservedHeaders lower-case names of the headers the adapter sets itself
     * @param string $keyHint appended to the message for an invalid key
     *
     * @throws InvalidArgumentException when the key or a header is invalid, or a header is one the adapter sets
     */
    public function __construct(
        #[SensitiveParameter]
        ?string $apiKey,
        #[SensitiveParameter]
        array $extraHeaders,
        array $reservedHeaders,
        string $keyHint = '',
    ) {
        if (null !== $apiKey && 1 !== preg_match(self::API_KEY, $apiKey)) {
            throw new InvalidArgumentException('The API key must be visible ASCII characters without spaces or line breaks' . $keyHint . '.');
        }

        $headers = [];
        foreach ($extraHeaders as $header => $value) {
            $header = (string) $header;
            if (1 !== preg_match(self::HEADER_NAME, $header)) {
                throw new InvalidArgumentException('An extra header has an invalid name.');
            }

            if (\in_array(strtolower($header), $reservedHeaders, true)) {
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
     * The URL is never quoted: it may contain a credential (user info, ?key=).
     *
     * @param string $subject how messages name the URL, e.g. 'The base URL of "openai"'
     *
     * @throws InvalidArgumentException when the URL has whitespace or control characters, is not absolute HTTP(S), or
     *                                  contains credentials, a query or a fragment
     */
    public static function checkBaseUrl(string $baseUrl, string $subject): void
    {
        // parse_url() accepts control characters, and the URL appears in the adapters' error messages.
        if (1 === preg_match('/[\x00-\x20\x7F]/', $baseUrl)) {
            throw new InvalidArgumentException(\sprintf('%s contains whitespace or control characters.', $subject));
        }

        $parts = parse_url($baseUrl);
        if (false === $parts || !\in_array($parts['scheme'] ?? null, ['http', 'https'], true) || !isset($parts['host'])) {
            throw new InvalidArgumentException(\sprintf('%s is not an absolute HTTP(S) URL.', $subject));
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException(\sprintf('%s must not contain credentials, a query or a fragment.', $subject));
        }
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey instanceof SensitiveParameterValue;
    }

    public function apiKey(): ?string
    {
        return $this->apiKey instanceof SensitiveParameterValue ? $this->reveal($this->apiKey) : null;
    }

    /**
     * @return array<string, string>
     */
    public function extraHeaders(): array
    {
        return array_map($this->reveal(...), $this->extraHeaders);
    }

    /**
     * @return array<string, string> header name → "[redacted]"
     */
    public function maskedExtraHeaders(): array
    {
        return array_map(static fn(): string => self::REDACTED, $this->extraHeaders);
    }

    /**
     * The text without the API key and extra header values of at least MIN_REDACTED_LENGTH characters, for messages
     * built from vendor or transport errors. Only exact copies are found, not URL-encoded or masked ones.
     */
    public function redact(string $text): string
    {
        $secrets = array_filter(array_map($this->reveal(...), $this->extraHeaders), static fn(string $secret): bool => \strlen($secret) >= self::MIN_REDACTED_LENGTH);
        if ($this->apiKey instanceof SensitiveParameterValue) {
            $secrets[] = $this->reveal($this->apiKey);
        }

        return str_replace($secrets, self::REDACTED, $text);
    }

    private function reveal(SensitiveParameterValue $secret): string
    {
        $value = $secret->getValue();

        return \is_string($value) ? $value : '';
    }
}
