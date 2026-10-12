<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Provider\Llm\Bridge;

use Closure;
use Exception;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\UntrustedText;
use HtmlTranslator\Provider\Llm\Bridge\HttpSecrets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpSecrets::class)]
#[UsesClass(InvalidArgumentException::class)]
#[UsesClass(UntrustedText::class)]
final class HttpSecretsTest extends TestCase
{
    private const string API_KEY = 'sk-test-SECRET-0123456789';

    private const array RESERVED = ['authorization', 'content-type'];

    protected function setUp(): void
    {
        parent::setUp();
        ini_set('zend.exception_ignore_args', '0');
    }

    #[Test]
    public function it_holds_an_optional_key_and_extra_headers(): void
    {
        $secrets = new HttpSecrets(self::API_KEY, ['X-Team' => 'translations', '123' => 'numeric'], self::RESERVED);
        $anonymous = new HttpSecrets(null, [], self::RESERVED);

        self::assertTrue($secrets->hasApiKey());
        self::assertSame(self::API_KEY, $secrets->apiKey());
        self::assertSame(['X-Team' => 'translations', '123' => 'numeric'], $secrets->extraHeaders());
        self::assertSame(['X-Team' => '[redacted]', '123' => '[redacted]'], $secrets->maskedExtraHeaders());
        self::assertFalse($anonymous->hasApiKey());
        self::assertNull($anonymous->apiKey());
    }

    #[Test]
    public function it_masks_the_key_for_debug_output(): void
    {
        self::assertSame('[redacted]', new HttpSecrets(self::API_KEY, [], self::RESERVED)->maskedApiKey());
        self::assertNull(new HttpSecrets(null, [], self::RESERVED)->maskedApiKey());
    }

    #[Test]
    public function it_tells_a_valid_header_value(): void
    {
        self::assertTrue(HttpSecrets::isHeaderValue("2023-06-01 \tv2 żółw"));
        self::assertTrue(HttpSecrets::isHeaderValue(''));
        self::assertFalse(HttpSecrets::isHeaderValue("a\r\nX-Injected: b"));
        self::assertFalse(HttpSecrets::isHeaderValue("a\x00b"));
    }

    #[Test]
    public function it_removes_the_key_always_and_header_values_from_eight_characters(): void
    {
        $secrets = new HttpSecrets('sk-1', ['X-Token' => 'tok12345', 'X-Team' => 'team1234', 'X-V' => 'v2'], self::RESERVED);

        self::assertSame('key [redacted], token [redacted], team [redacted], version v2', $secrets->redact('key sk-1, token tok12345, team team1234, version v2'));
        self::assertSame('nothing secret', new HttpSecrets(null, [], self::RESERVED)->redact('nothing secret'));
    }

    #[Test]
    public function it_keeps_its_secrets_out_of_dumps_and_serialization(): void
    {
        $secrets = new HttpSecrets(self::API_KEY, ['X-Token' => 'gateway-SECRET'], self::RESERVED);

        self::assertStringNotContainsString(self::API_KEY, print_r($secrets, true));
        self::assertStringNotContainsString('gateway-SECRET', var_export($secrets, true));

        $this->expectException(Exception::class);
        serialize($secrets);
    }

    /**
     * @return iterable<string, array{Closure(): HttpSecrets, string}>
     */
    public static function invalidSecrets(): iterable
    {
        yield 'key with a line break' => [static fn(): HttpSecrets => new HttpSecrets(self::API_KEY . "\n", [], self::RESERVED, '; pass null for none'), 'The API key must be visible ASCII characters without spaces or line breaks; pass null for none.'];
        yield 'blank key' => [static fn(): HttpSecrets => new HttpSecrets('', [], self::RESERVED), 'The API key must be visible ASCII characters without spaces or line breaks.'];
        yield 'invalid header name' => [static fn(): HttpSecrets => new HttpSecrets(null, ['X Team' => 'x'], self::RESERVED), 'An extra header has an invalid name.'];
        yield 'reserved header in another case' => [static fn(): HttpSecrets => new HttpSecrets(null, ['Content-Type' => 'text/plain'], self::RESERVED), 'The header "Content-Type" is set by the adapter.'];
        yield 'header value with a line break' => [static fn(): HttpSecrets => new HttpSecrets(null, ['X-Team' => "a\r\nX-Injected: b"], self::RESERVED), 'The value of header "X-Team" contains line breaks or control characters.'];
    }

    /**
     * @param Closure(): HttpSecrets $create
     */
    #[Test]
    #[DataProvider('invalidSecrets')]
    public function it_rejects_invalid_secrets_without_quoting_them(Closure $create, string $message): void
    {
        try {
            $create();
            self::fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $exception->getTraceAsString());
            // Only the library's frames: the runner's frames hold the whole PHPUnit object graph, and dumping them
            // exhausts memory with some PHPUnit versions.
            $frames = array_filter($exception->getTrace(), static fn(array $frame): bool => str_starts_with($frame['class'] ?? '', 'HtmlTranslator\\Provider\\'));
            self::assertNotSame([], $frames);
            self::assertStringNotContainsString(self::API_KEY, print_r(array_column($frames, 'args'), true));
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidBaseUrls(): iterable
    {
        yield 'line break' => ["https://api.example.test/v1\nX", 'The base URL contains whitespace or control characters.'];
        yield 'space' => ['https://api.example.test/my api', 'The base URL contains whitespace or control characters.'];
        yield 'relative' => ['/v1', 'The base URL is not an absolute HTTP(S) URL.'];
        yield 'not HTTP' => ['ftp://api.example.test/v1', 'The base URL is not an absolute HTTP(S) URL.'];
        yield 'no host' => ['http:/v1', 'The base URL is not an absolute HTTP(S) URL.'];
        yield 'unparsable' => ['https:///v1', 'The base URL is not an absolute HTTP(S) URL.'];
        yield 'credentials' => ['https://user:pw@api.example.test/v1', 'The base URL must not contain credentials, a query or a fragment.'];
        yield 'token as user' => ['https://token@api.example.test/v1', 'The base URL must not contain credentials, a query or a fragment.'];
        yield 'query' => ['https://api.example.test/v1?key=1', 'The base URL must not contain credentials, a query or a fragment.'];
        yield 'fragment' => ['https://api.example.test/v1#top', 'The base URL must not contain credentials, a query or a fragment.'];
    }

    #[Test]
    #[DataProvider('invalidBaseUrls')]
    public function it_rejects_invalid_base_urls_without_quoting_them(string $baseUrl, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        HttpSecrets::checkBaseUrl($baseUrl, 'The base URL');
    }

    #[Test]
    public function it_accepts_absolute_http_and_https_urls(): void
    {
        HttpSecrets::checkBaseUrl('http://localhost:11434/v1', 'The base URL');
        HttpSecrets::checkBaseUrl('https://api.anthropic.com/v1/', 'The base URL');

        $this->addToAssertionCount(1);
    }
}
