<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Support;

use HtmlTranslator\Tests\Support\IntegrationProviders;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * A mistake here silently skips integration tests or calls the wrong endpoint, so it is tested without network.
 */
#[CoversNothing]
final class IntegrationProvidersTest extends TestCase
{
    private const array VARIABLES = [
        'HTML_TRANSLATOR_OPENAI_API_KEY', 'HTML_TRANSLATOR_OPENAI_MODEL',
        'HTML_TRANSLATOR_ANTHROPIC_API_KEY', 'HTML_TRANSLATOR_ANTHROPIC_MODEL',
        'HTML_TRANSLATOR_GEMINI_API_KEY', 'HTML_TRANSLATOR_GEMINI_MODEL',
        'HTML_TRANSLATOR_OLLAMA_MODEL', 'HTML_TRANSLATOR_OLLAMA_BASE_URL',
        'HTML_TRANSLATOR_VLLM_MODEL', 'HTML_TRANSLATOR_VLLM_BASE_URL', 'HTML_TRANSLATOR_VLLM_API_KEY',
        'HTML_TRANSLATOR_LMSTUDIO_MODEL', 'HTML_TRANSLATOR_LMSTUDIO_BASE_URL',
    ];

    /** @var array<string, string|false> */
    private array $backup = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::VARIABLES as $variable) {
            $this->backup[$variable] = getenv($variable);
            putenv($variable);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->backup as $variable => $value) {
            putenv(false === $value ? $variable : $variable . '=' . $value);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_configures_nothing_without_variables(): void
    {
        foreach (IntegrationProviders::names() as $provider) {
            self::assertNull(IntegrationProviders::config($provider), $provider);
        }
    }

    #[Test]
    public function it_treats_blank_variables_as_unset(): void
    {
        putenv('HTML_TRANSLATOR_OPENAI_API_KEY=   ');
        putenv('HTML_TRANSLATOR_OLLAMA_MODEL=');

        self::assertNull(IntegrationProviders::config('openai'));
        self::assertNull(IntegrationProviders::config('ollama'));
    }

    /**
     * @return iterable<string, array{string, list<string>, string, string, ?string, string}>
     */
    public static function configurations(): iterable
    {
        yield 'Anthropic with the default model' => ['anthropic', ['HTML_TRANSLATOR_ANTHROPIC_API_KEY=sk-ant-test-1'], 'anthropic:claude-opus-5-5', 'https://api.anthropic.com/v1/messages', 'sk-ant-test-1', 'x-api-key'];
        yield 'Anthropic with a model' => ['anthropic', ['HTML_TRANSLATOR_ANTHROPIC_API_KEY=sk-ant-test-1', 'HTML_TRANSLATOR_ANTHROPIC_MODEL=claude-sonnet-5-5'], 'anthropic:claude-sonnet-5-5', 'https://api.anthropic.com/v1/messages', 'sk-ant-test-1', 'x-api-key'];
        yield 'OpenAI with the default model' => ['openai', ['HTML_TRANSLATOR_OPENAI_API_KEY=sk-test-1'], 'openai:gpt-4o-mini', 'https://api.openai.com/v1/chat/completions', 'Bearer sk-test-1', 'Authorization'];
        yield 'OpenAI with a model' => ['openai', ['HTML_TRANSLATOR_OPENAI_API_KEY= sk-test-1 ', 'HTML_TRANSLATOR_OPENAI_MODEL=gpt-4.1'], 'openai:gpt-4.1', 'https://api.openai.com/v1/chat/completions', 'Bearer sk-test-1', 'Authorization'];
        yield 'Gemini' => ['gemini', ['HTML_TRANSLATOR_GEMINI_API_KEY=g-key-123'], 'gemini:gemini-2.5-flash', 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions', 'Bearer g-key-123', 'Authorization'];
        yield 'Ollama on the default URL' => ['ollama', ['HTML_TRANSLATOR_OLLAMA_MODEL=llama3.1'], 'ollama:llama3.1', 'http://localhost:11434/v1/chat/completions', null, 'Authorization'];
        yield 'Ollama elsewhere' => ['ollama', ['HTML_TRANSLATOR_OLLAMA_MODEL=llama3.1', 'HTML_TRANSLATOR_OLLAMA_BASE_URL=http://gpu:11434/v1'], 'ollama:llama3.1', 'http://gpu:11434/v1/chat/completions', null, 'Authorization'];
        yield 'vLLM with a key' => ['vllm', ['HTML_TRANSLATOR_VLLM_MODEL=qwen', 'HTML_TRANSLATOR_VLLM_API_KEY=v-key-123'], 'vllm:qwen', 'http://localhost:8000/v1/chat/completions', 'Bearer v-key-123', 'Authorization'];
        yield 'LM Studio' => ['lmstudio', ['HTML_TRANSLATOR_LMSTUDIO_MODEL=qwen2.5', 'HTML_TRANSLATOR_LMSTUDIO_BASE_URL=http://studio:1234/v1'], 'lmstudio:qwen2.5', 'http://studio:1234/v1/chat/completions', null, 'Authorization'];
    }

    /**
     * @param list<string> $variables
     */
    #[Test]
    #[DataProvider('configurations')]
    public function it_reads_the_configuration_from_the_environment(string $provider, array $variables, string $modelId, string $endpoint, ?string $credential, string $credentialHeader): void
    {
        foreach ($variables as $variable) {
            putenv($variable);
        }

        $config = IntegrationProviders::config($provider) ?? self::fail('The provider should be configured.');

        self::assertSame($modelId, $config->modelId());
        self::assertSame($endpoint, $config->endpoint());
        self::assertSame($credential, $config->headers()[$credentialHeader] ?? null);
        self::assertSame($modelId, IntegrationProviders::chatModel($config)->modelId());
    }

    #[Test]
    public function its_arguments_override_the_environment(): void
    {
        putenv('HTML_TRANSLATOR_OLLAMA_MODEL=llama3.1');

        $config = IntegrationProviders::config('ollama', 'qwen2.5', 'http://other:11434/v1') ?? self::fail('Ollama should be configured.');

        self::assertSame('ollama:qwen2.5', $config->modelId());
        self::assertSame('http://other:11434/v1/chat/completions', $config->endpoint());
        self::assertNotNull(IntegrationProviders::config('lmstudio', 'qwen2.5'), 'A model argument enables a local server.');
        self::assertNull(IntegrationProviders::config('openai', 'gpt-4.1'), 'A hosted API still needs its key.');
    }

    #[Test]
    public function it_names_the_variable_that_enables_a_provider(): void
    {
        self::assertSame('HTML_TRANSLATOR_OPENAI_API_KEY', IntegrationProviders::requiredVariable('openai'));
        self::assertSame('HTML_TRANSLATOR_OLLAMA_MODEL', IntegrationProviders::requiredVariable('ollama'));
    }

    #[Test]
    public function it_rejects_an_unknown_provider(): void
    {
        $this->expectException(UnexpectedValueException::class);

        IntegrationProviders::config('mistral');
    }
}
