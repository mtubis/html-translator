<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Provider\Llm\Bridge\OpenAiCompatible;

use Closure;
use Exception;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\UntrustedText;
use HtmlTranslator\Provider\Llm\Bridge\HttpSecrets;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenAiCompatibleConfig::class)]
#[UsesClass(InvalidArgumentException::class)]
#[UsesClass(UntrustedText::class)]
#[UsesClass(HttpSecrets::class)]
final class OpenAiCompatibleConfigTest extends TestCase
{
    private const string API_KEY = 'sk-test-SECRET-0123456789';

    /**
     * Traces with arguments, so a key passed as an argument would show up in getTraceAsString().
     */
    protected function setUp(): void
    {
        parent::setUp();
        ini_set('zend.exception_ignore_args', '0');
    }

    /**
     * @return iterable<string, array{OpenAiCompatibleConfig, string, string, ?string, bool, string}>
     */
    public static function presets(): iterable
    {
        yield 'OpenAI' => [OpenAiCompatibleConfig::openAi(self::API_KEY, 'gpt-4o-mini'), 'openai:gpt-4o-mini', 'https://api.openai.com/v1/chat/completions', 'Bearer ' . self::API_KEY, true, 'max_completion_tokens'];
        yield 'Gemini' => [OpenAiCompatibleConfig::gemini(self::API_KEY, 'gemini-2.5-flash'), 'gemini:gemini-2.5-flash', 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions', 'Bearer ' . self::API_KEY, true, 'max_tokens'];
        yield 'Ollama' => [OpenAiCompatibleConfig::ollama('llama3.1'), 'ollama:llama3.1', 'http://localhost:11434/v1/chat/completions', null, true, 'max_tokens'];
        yield 'Ollama on another host' => [OpenAiCompatibleConfig::ollama('llama3.1', 'http://gpu-box:11434/v1/'), 'ollama:llama3.1', 'http://gpu-box:11434/v1/chat/completions', null, true, 'max_tokens'];
        yield 'vLLM' => [OpenAiCompatibleConfig::vllm('Qwen/Qwen2.5-7B-Instruct'), 'vllm:Qwen/Qwen2.5-7B-Instruct', 'http://localhost:8000/v1/chat/completions', null, true, 'max_tokens'];
        yield 'vLLM with a key' => [OpenAiCompatibleConfig::vllm('qwen', 'https://vllm.example.test/v1', self::API_KEY), 'vllm:qwen', 'https://vllm.example.test/v1/chat/completions', 'Bearer ' . self::API_KEY, true, 'max_tokens'];
        yield 'LM Studio' => [OpenAiCompatibleConfig::lmStudio('qwen2.5-7b-instruct'), 'lmstudio:qwen2.5-7b-instruct', 'http://localhost:1234/v1/chat/completions', null, false, 'max_tokens'];
    }

    #[Test]
    #[DataProvider('presets')]
    public function its_presets_have_the_documented_settings(OpenAiCompatibleConfig $config, string $modelId, string $endpoint, ?string $authorization, bool $jsonMode, string $maxTokensField): void
    {
        self::assertSame($modelId, $config->modelId());
        self::assertSame($endpoint, $config->endpoint());
        self::assertSame($authorization, $config->headers()['Authorization'] ?? null);
        self::assertSame($jsonMode, $config->jsonMode);
        self::assertSame($maxTokensField, $config->maxTokensField);
    }

    #[Test]
    public function it_sends_json_and_extra_headers(): void
    {
        $config = new OpenAiCompatibleConfig('azure-proxy', 'https://proxy.example.test/v1', 'gpt-4o', self::API_KEY, extraHeaders: ['OpenAI-Organization' => 'org-123']);

        self::assertSame([
            'OpenAI-Organization' => 'org-123',
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . self::API_KEY,
        ], $config->headers());
    }

    #[Test]
    public function it_removes_its_key_from_text(): void
    {
        $config = OpenAiCompatibleConfig::openAi(self::API_KEY, 'gpt-4o-mini');

        self::assertSame('Incorrect API key provided: [redacted], twice: [redacted].', $config->redact('Incorrect API key provided: ' . self::API_KEY . ', twice: ' . self::API_KEY . '.'));
        self::assertSame('No key here.', OpenAiCompatibleConfig::ollama('llama3.1')->redact('No key here.'));
    }

    #[Test]
    public function it_removes_even_a_short_key_from_text(): void
    {
        self::assertSame('Rejected token [redacted].', OpenAiCompatibleConfig::vllm('qwen', 'http://localhost:8000/v1', 'abc123')->redact('Rejected token abc123.'));
    }

    #[Test]
    public function it_keeps_its_key_out_of_dumps(): void
    {
        $config = OpenAiCompatibleConfig::openAi(self::API_KEY, 'gpt-4o-mini');

        self::assertStringNotContainsString(self::API_KEY, print_r($config, true));
        self::assertStringNotContainsString(self::API_KEY, var_export($config, true));
        self::assertStringNotContainsString(self::API_KEY, print_r((array) $config, true));
        self::assertSame([
            'name' => 'openai',
            'baseUrl' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o-mini',
            'apiKey' => '[redacted]',
            'jsonMode' => true,
            'maxTokensField' => 'max_completion_tokens',
            'extraHeaders' => [],
        ], $config->__debugInfo());
        self::assertArrayHasKey('apiKey', $local = OpenAiCompatibleConfig::ollama('llama3.1')->__debugInfo());
        self::assertNull($local['apiKey']);
    }

    #[Test]
    public function it_cannot_be_serialized_with_its_secrets(): void
    {
        $this->expectException(Exception::class);

        serialize(OpenAiCompatibleConfig::openAi(self::API_KEY, 'gpt-4o-mini'));
    }

    #[Test]
    public function it_treats_extra_header_values_as_secrets(): void
    {
        $config = new OpenAiCompatibleConfig('azure', 'https://azure.example.test/openai/v1', 'gpt-4o', extraHeaders: ['api-key' => 'azure-SECRET-key', 'X-Version' => 'v2', 'X-Team' => 'team-123']);

        self::assertSame('azure-SECRET-key', $config->headers()['api-key'] ?? null);
        self::assertSame(['api-key' => '[redacted]', 'X-Version' => '[redacted]', 'X-Team' => '[redacted]'], $config->__debugInfo()['extraHeaders']);
        self::assertStringNotContainsString('azure-SECRET-key', var_export($config, true));
        self::assertSame('Rejected [redacted] of [redacted] for v2.', $config->redact('Rejected azure-SECRET-key of team-123 for v2.'));
    }

    /**
     * @return iterable<string, array{Closure(): OpenAiCompatibleConfig}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'name over 100 characters' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig(str_repeat('n', 101), 'https://api.example.test/v1', 'model')];
        yield 'name with a line break' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig("my\nproxy", 'https://api.example.test/v1', 'model')];
        yield 'base URL with a line break' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig('x', "https://api.example.test/v1\nX", 'model')];
        yield 'base URL with a space' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig('x', 'https://api.example.test/my api/v1', 'model')];
        yield 'blank name' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig(' ', 'https://api.example.test/v1', 'model')];
        yield 'relative URL' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig('x', '/v1', 'model')];
        yield 'not HTTP' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig('x', 'ftp://api.example.test/v1', 'model')];
        yield 'URL with a query' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig('x', 'https://api.example.test/v1?key=1', 'model')];
        yield 'blank model' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig('x', 'https://api.example.test/v1', '')];
        yield 'blank key' => [static fn(): OpenAiCompatibleConfig => OpenAiCompatibleConfig::openAi(' ', 'gpt-4o-mini')];
        yield 'unknown limit field' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig('x', 'https://api.example.test/v1', 'model', maxTokensField: 'max_output_tokens')];
        yield 'URL with credentials' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig('x', 'https://user:secret@api.example.test/v1', 'model')];
        yield 'URL with a token as user' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig('x', 'https://token@api.example.test/v1', 'model')];
        yield 'URL with a fragment' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig('x', 'https://api.example.test/v1#top', 'model')];
        yield 'unparsable URL' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig('x', 'https:///v1', 'model')];
        yield 'URL without host' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig('x', 'http:/v1', 'model')];
        yield 'key with a trailing line break' => [static fn(): OpenAiCompatibleConfig => OpenAiCompatibleConfig::openAi(self::API_KEY . "\n", 'gpt-4o-mini')];
        yield 'key with a space' => [static fn(): OpenAiCompatibleConfig => OpenAiCompatibleConfig::openAi('sk-test 123', 'gpt-4o-mini')];
        yield 'header overriding authorization' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig('x', 'https://api.example.test/v1', 'model', extraHeaders: ['AUTHORIZATION' => 'Basic abc'])];
        yield 'header overriding the content type' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig('x', 'https://api.example.test/v1', 'model', extraHeaders: ['Content-Type' => 'text/plain'])];
        yield 'header overriding accept' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig('x', 'https://api.example.test/v1', 'model', extraHeaders: ['accept' => 'text/plain'])];
        yield 'empty header name' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig('x', 'https://api.example.test/v1', 'model', extraHeaders: ['' => 'x'])];
        yield 'header name with a space' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig('x', 'https://api.example.test/v1', 'model', extraHeaders: ['X Org' => 'x'])];
        yield 'header value with a line break' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig('x', 'https://api.example.test/v1', 'model', extraHeaders: ['X-Org' => "a\r\nX-Injected: b"])];
    }

    /**
     * @param Closure(): OpenAiCompatibleConfig $create
     */
    #[Test]
    #[DataProvider('invalidConfigs')]
    public function it_rejects_invalid_settings(Closure $create): void
    {
        $this->expectException(InvalidArgumentException::class);

        $create();
    }

    /**
     * @return iterable<string, array{Closure(): OpenAiCompatibleConfig}>
     */
    public static function invalidConfigsWithSecrets(): iterable
    {
        yield 'blank model' => [static fn(): OpenAiCompatibleConfig => OpenAiCompatibleConfig::openAi(self::API_KEY, ' ')];
        yield 'key with a line break' => [static fn(): OpenAiCompatibleConfig => OpenAiCompatibleConfig::openAi(self::API_KEY . "\n", 'gpt-4o-mini')];
        yield 'key in the URL' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig('x', 'https://api.example.test/v1?key=' . self::API_KEY, 'model')];
        yield 'key as URL password' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig('x', 'https://user:' . self::API_KEY . '@api.example.test/v1', 'model')];
        yield 'key in a bad header value' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig('x', 'https://api.example.test/v1', 'model', extraHeaders: ['api-key' => self::API_KEY . "\n"])];
    }

    /**
     * @param Closure(): OpenAiCompatibleConfig $create
     */
    #[Test]
    #[DataProvider('invalidConfigsWithSecrets')]
    public function it_keeps_secrets_out_of_validation_errors(Closure $create): void
    {
        try {
            $create();
            self::fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString(self::API_KEY, $exception->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $exception->getTraceAsString());
        }
    }

    #[Test]
    public function it_accepts_a_name_of_100_characters(): void
    {
        self::assertSame(str_repeat('n', 100), new OpenAiCompatibleConfig(str_repeat('n', 100), 'https://api.example.test/v1', 'model')->name);
        self::assertSame(str_repeat('ż', 100), new OpenAiCompatibleConfig(str_repeat('ż', 100), 'https://api.example.test/v1', 'model')->name);
    }

    #[Test]
    public function it_accepts_numeric_header_names(): void
    {
        self::assertSame('1', new OpenAiCompatibleConfig('x', 'https://api.example.test/v1', 'model', extraHeaders: ['123' => '1'])->headers()[123] ?? null);
    }
}
