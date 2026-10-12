<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Support;

use Closure;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicChatModel;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicConfig;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleChatModel;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleConfig;
use HtmlTranslator\Provider\Llm\ChatModelInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use UnexpectedValueException;

/**
 * Real chat models configured from environment variables (documented in .env.dist), for the integration tests and
 * tools/eval. Load .env into the environment first: `set -a; . ./.env; set +a`.
 */
final class IntegrationProviders
{
    /** Translation batches take a while on local models. */
    private const int TIMEOUT_SECONDS = 300;

    /**
     * provider → [required variable, model variable, default model, base URL variable]
     *
     * @var array<string, array{string, string, ?string, ?string}>
     */
    private const array PROVIDERS = [
        'openai' => ['HTML_TRANSLATOR_OPENAI_API_KEY', 'HTML_TRANSLATOR_OPENAI_MODEL', 'gpt-4o-mini', null],
        'anthropic' => ['HTML_TRANSLATOR_ANTHROPIC_API_KEY', 'HTML_TRANSLATOR_ANTHROPIC_MODEL', 'claude-opus-5-5', null],
        'gemini' => ['HTML_TRANSLATOR_GEMINI_API_KEY', 'HTML_TRANSLATOR_GEMINI_MODEL', 'gemini-2.5-flash', null],
        'ollama' => ['HTML_TRANSLATOR_OLLAMA_MODEL', 'HTML_TRANSLATOR_OLLAMA_MODEL', null, 'HTML_TRANSLATOR_OLLAMA_BASE_URL'],
        'vllm' => ['HTML_TRANSLATOR_VLLM_MODEL', 'HTML_TRANSLATOR_VLLM_MODEL', null, 'HTML_TRANSLATOR_VLLM_BASE_URL'],
        'lmstudio' => ['HTML_TRANSLATOR_LMSTUDIO_MODEL', 'HTML_TRANSLATOR_LMSTUDIO_MODEL', null, 'HTML_TRANSLATOR_LMSTUDIO_BASE_URL'],
    ];

    private function __construct() {}

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::PROVIDERS);
    }

    /**
     * The variable that enables the provider.
     *
     * @throws UnexpectedValueException for an unknown provider
     */
    public static function requiredVariable(string $provider): string
    {
        return self::definition($provider)[0];
    }

    /**
     * The provider's configuration from the environment, with optional overrides; null when it is not configured:
     * hosted APIs need their key, local servers a model (from the environment or $model). $baseUrl applies to local
     * servers and Anthropic (e.g. a gateway).
     *
     * @throws UnexpectedValueException for an unknown provider
     */
    public static function config(string $provider, ?string $model = null, ?string $baseUrl = null): OpenAiCompatibleConfig|AnthropicConfig|null
    {
        [$required, $modelVariable, $defaultModel, $baseUrlVariable] = self::definition($provider);
        $model ??= self::variable($modelVariable) ?? $defaultModel;
        $baseUrl ??= null === $baseUrlVariable ? null : self::variable($baseUrlVariable);

        return match ($provider) {
            'openai' => self::withKey($required, static fn(string $key): OpenAiCompatibleConfig => OpenAiCompatibleConfig::openAi($key, $model ?? '')),
            'anthropic' => self::withKey($required, static fn(string $key): AnthropicConfig => new AnthropicConfig($model ?? '', $key, baseUrl: $baseUrl ?? AnthropicConfig::DEFAULT_BASE_URL)),
            'gemini' => self::withKey($required, static fn(string $key): OpenAiCompatibleConfig => OpenAiCompatibleConfig::gemini($key, $model ?? '')),
            'ollama' => null === $model ? null : (null === $baseUrl ? OpenAiCompatibleConfig::ollama($model) : OpenAiCompatibleConfig::ollama($model, $baseUrl)),
            'vllm' => null === $model ? null : OpenAiCompatibleConfig::vllm($model, $baseUrl ?? 'http://localhost:8000/v1', self::variable('HTML_TRANSLATOR_VLLM_API_KEY')),
            'lmstudio' => null === $model ? null : (null === $baseUrl ? OpenAiCompatibleConfig::lmStudio($model) : OpenAiCompatibleConfig::lmStudio($model, $baseUrl)),
            default => throw new UnexpectedValueException(\sprintf('No configuration for provider "%s".', $provider)),
        };
    }

    public static function chatModel(OpenAiCompatibleConfig|AnthropicConfig $config): ChatModelInterface
    {
        $client = new Psr18Client(HttpClient::create(['timeout' => self::TIMEOUT_SECONDS]));

        return $config instanceof AnthropicConfig
            ? new AnthropicChatModel($client, $client, $client, $config)
            : new OpenAiCompatibleChatModel($client, $client, $client, $config);
    }

    /**
     * @return array{string, string, ?string, ?string}
     */
    private static function definition(string $provider): array
    {
        return self::PROVIDERS[$provider] ?? throw new UnexpectedValueException(\sprintf('Unknown provider "%s"; known: %s.', $provider, implode(', ', self::names())));
    }

    /**
     * @template T of OpenAiCompatibleConfig|AnthropicConfig
     *
     * @param Closure(string): T $create
     *
     * @return T|null
     */
    private static function withKey(string $variable, Closure $create): OpenAiCompatibleConfig|AnthropicConfig|null
    {
        $key = self::variable($variable);

        return null === $key ? null : $create($key);
    }

    private static function variable(string $name): ?string
    {
        $value = getenv($name);

        return false === $value || '' === trim($value) ? null : trim($value);
    }
}
