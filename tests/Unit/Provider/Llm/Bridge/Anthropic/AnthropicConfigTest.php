<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Provider\Llm\Bridge\Anthropic;

use Closure;
use Exception;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\UntrustedText;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicConfig;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicEffort;
use HtmlTranslator\Provider\Llm\Bridge\HttpSecrets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnthropicConfig::class)]
#[UsesClass(InvalidArgumentException::class)]
#[UsesClass(UntrustedText::class)]
#[UsesClass(HttpSecrets::class)]
final class AnthropicConfigTest extends TestCase
{
    private const string API_KEY = 'sk-ant-test-SECRET-0123456789';

    /**
     * Traces with arguments, so a key passed as an argument would show up in getTraceAsString().
     */
    protected function setUp(): void
    {
        parent::setUp();
        ini_set('zend.exception_ignore_args', '0');
    }

    #[Test]
    public function it_has_the_documented_defaults(): void
    {
        $config = new AnthropicConfig('claude-opus-5-5', self::API_KEY);

        self::assertSame('anthropic:claude-opus-5-5', $config->modelId());
        self::assertSame('https://api.anthropic.com/v1/messages', $config->endpoint());
        self::assertSame(16000, $config->defaultMaxOutputTokens);
        self::assertNull($config->effort);
        self::assertFalse($config->refusalFallbacks);
        self::assertSame([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'anthropic-version' => '2023-06-01',
            'x-api-key' => self::API_KEY,
        ], $config->headers());
    }

    #[Test]
    public function it_sends_extra_headers_the_api_version_and_the_fallback_beta(): void
    {
        $config = new AnthropicConfig('claude-opus-5-5', self::API_KEY, baseUrl: 'https://gateway.example.test/anthropic/v1/', apiVersion: '2023-06-01', refusalFallbacks: true, extraHeaders: ['X-Team' => 'translations']);

        self::assertSame('https://gateway.example.test/anthropic/v1/messages', $config->endpoint());
        self::assertSame([
            'X-Team' => 'translations',
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'anthropic-version' => '2023-06-01',
            'x-api-key' => self::API_KEY,
            'anthropic-beta' => 'server-side-fallback-2026-07-01',
        ], $config->headers());
    }

    #[Test]
    public function it_keeps_the_effort_and_shows_it_in_dumps(): void
    {
        $config = new AnthropicConfig('claude-opus-5-5', self::API_KEY, effort: AnthropicEffort::XHigh);

        self::assertSame(AnthropicEffort::XHigh, $config->effort);
        self::assertSame('xhigh', $config->__debugInfo()['effort']);
    }

    #[Test]
    public function it_lets_a_gateway_header_through(): void
    {
        $config = new AnthropicConfig('claude-opus-5-5', self::API_KEY, extraHeaders: ['Authorization' => 'Bearer gateway-token', '123' => 'numeric-name']);

        self::assertSame('Bearer gateway-token', $config->headers()['Authorization'] ?? null);
        self::assertSame('numeric-name', $config->headers()['123'] ?? null);
    }

    #[Test]
    public function it_accepts_an_output_limit_of_one(): void
    {
        self::assertSame(1, new AnthropicConfig('claude-opus-5-5', self::API_KEY, defaultMaxOutputTokens: 1)->defaultMaxOutputTokens);
    }

    #[Test]
    public function it_removes_its_key_and_header_values_from_text(): void
    {
        $config = new AnthropicConfig('claude-opus-5-5', self::API_KEY, extraHeaders: ['X-Gateway-Token' => 'gateway-SECRET', 'X-Team' => 'team1234', 'X-V' => 'v2']);

        self::assertSame(
            'invalid x-api-key [redacted] via [redacted] of [redacted] for v2.',
            $config->redact('invalid x-api-key ' . self::API_KEY . ' via gateway-SECRET of team1234 for v2.'),
        );
    }

    #[Test]
    public function it_removes_even_a_short_key(): void
    {
        self::assertSame('bad key [redacted]', new AnthropicConfig('claude-opus-5-5', 'sk-1')->redact('bad key sk-1'));
    }

    #[Test]
    public function it_keeps_its_secrets_out_of_dumps(): void
    {
        $config = new AnthropicConfig('claude-opus-5-5', self::API_KEY, extraHeaders: ['X-Gateway-Token' => 'gateway-SECRET']);

        self::assertStringNotContainsString(self::API_KEY, print_r($config, true));
        self::assertStringNotContainsString(self::API_KEY, var_export($config, true));
        self::assertStringNotContainsString('gateway-SECRET', var_export($config, true));
        self::assertSame([
            'baseUrl' => 'https://api.anthropic.com/v1',
            'model' => 'claude-opus-5-5',
            'apiKey' => '[redacted]',
            'apiVersion' => '2023-06-01',
            'defaultMaxOutputTokens' => 16000,
            'effort' => null,
            'refusalFallbacks' => false,
            'extraHeaders' => ['X-Gateway-Token' => '[redacted]'],
        ], $config->__debugInfo());
    }

    #[Test]
    public function it_cannot_be_serialized_with_its_secrets(): void
    {
        $this->expectException(Exception::class);

        serialize(new AnthropicConfig('claude-opus-5-5', self::API_KEY));
    }

    /**
     * @return iterable<string, array{Closure(): AnthropicConfig}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'blank model' => [static fn(): AnthropicConfig => new AnthropicConfig(' ', self::API_KEY)];
        yield 'blank key' => [static fn(): AnthropicConfig => new AnthropicConfig('claude-opus-5-5', '')];
        yield 'key with a line break' => [static fn(): AnthropicConfig => new AnthropicConfig('claude-opus-5-5', self::API_KEY . "\n")];
        yield 'relative URL' => [static fn(): AnthropicConfig => new AnthropicConfig('claude-opus-5-5', self::API_KEY, baseUrl: '/v1')];
        yield 'URL with a query' => [static fn(): AnthropicConfig => new AnthropicConfig('claude-opus-5-5', self::API_KEY, baseUrl: 'https://api.example.test/v1?key=1')];
        yield 'URL with credentials' => [static fn(): AnthropicConfig => new AnthropicConfig('claude-opus-5-5', self::API_KEY, baseUrl: 'https://user:pw@api.example.test/v1')];
        yield 'URL with a line break' => [static fn(): AnthropicConfig => new AnthropicConfig('claude-opus-5-5', self::API_KEY, baseUrl: "https://api.example.test/v1\nX")];
        yield 'URL with a token as user' => [static fn(): AnthropicConfig => new AnthropicConfig('claude-opus-5-5', self::API_KEY, baseUrl: 'https://token@api.example.test/v1')];
        yield 'URL with a fragment' => [static fn(): AnthropicConfig => new AnthropicConfig('claude-opus-5-5', self::API_KEY, baseUrl: 'https://api.example.test/v1#top')];
        yield 'URL that is not HTTP' => [static fn(): AnthropicConfig => new AnthropicConfig('claude-opus-5-5', self::API_KEY, baseUrl: 'ftp://api.example.test/v1')];
        yield 'URL without host' => [static fn(): AnthropicConfig => new AnthropicConfig('claude-opus-5-5', self::API_KEY, baseUrl: 'http:/v1')];
        yield 'unparsable URL' => [static fn(): AnthropicConfig => new AnthropicConfig('claude-opus-5-5', self::API_KEY, baseUrl: 'https:///v1')];
        yield 'blank API version' => [static fn(): AnthropicConfig => new AnthropicConfig('claude-opus-5-5', self::API_KEY, apiVersion: ' ')];
        yield 'API version with a line break' => [static fn(): AnthropicConfig => new AnthropicConfig('claude-opus-5-5', self::API_KEY, apiVersion: "2023-06-01\r\nX: y")];
        yield 'output limit of zero' => [static fn(): AnthropicConfig => new AnthropicConfig('claude-opus-5-5', self::API_KEY, defaultMaxOutputTokens: 0)];
        yield 'header overriding the key' => [static fn(): AnthropicConfig => new AnthropicConfig('claude-opus-5-5', self::API_KEY, extraHeaders: ['X-Api-Key' => 'other'])];
        yield 'header overriding the version' => [static fn(): AnthropicConfig => new AnthropicConfig('claude-opus-5-5', self::API_KEY, extraHeaders: ['anthropic-version' => '2024-01-01'])];
        yield 'header overriding the beta header' => [static fn(): AnthropicConfig => new AnthropicConfig('claude-opus-5-5', self::API_KEY, extraHeaders: ['Anthropic-Beta' => 'x'])];
        yield 'header overriding the content type' => [static fn(): AnthropicConfig => new AnthropicConfig('claude-opus-5-5', self::API_KEY, extraHeaders: ['content-type' => 'text/plain'])];
        yield 'header name with a space' => [static fn(): AnthropicConfig => new AnthropicConfig('claude-opus-5-5', self::API_KEY, extraHeaders: ['X Team' => 'x'])];
        yield 'header value with a line break' => [static fn(): AnthropicConfig => new AnthropicConfig('claude-opus-5-5', self::API_KEY, extraHeaders: ['X-Team' => "a\r\nX-Injected: b"])];
    }

    /**
     * @param Closure(): AnthropicConfig $create
     */
    #[Test]
    #[DataProvider('invalidConfigs')]
    public function it_rejects_invalid_settings(Closure $create): void
    {
        $this->expectException(InvalidArgumentException::class);

        $create();
    }

    #[Test]
    public function it_keeps_its_key_out_of_validation_errors(): void
    {
        try {
            new AnthropicConfig('claude-opus-5-5', self::API_KEY . "\n");
            self::fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString(self::API_KEY, $exception->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $exception->getTraceAsString());
        }
    }
}
