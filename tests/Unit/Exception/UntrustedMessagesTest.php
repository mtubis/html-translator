<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Exception;

use Closure;
use HtmlTranslator\Domain\InlineTag;
use HtmlTranslator\Domain\InlineTagKind;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Domain\LocalePair;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Domain\UnitKind;
use HtmlTranslator\Exception\TranslatorException;
use HtmlTranslator\FallbackStrategy;
use HtmlTranslator\Html\SkipRules;
use HtmlTranslator\Html\TranslatableAttributes;
use HtmlTranslator\HtmlTranslator;
use HtmlTranslator\Issue;
use HtmlTranslator\IssueType;
use HtmlTranslator\Provider\BatchResult;
use HtmlTranslator\Provider\Correction;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicChatModel;
use HtmlTranslator\Provider\Llm\Bridge\Anthropic\AnthropicConfig;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleChatModel;
use HtmlTranslator\Provider\Llm\Bridge\OpenAiCompatible\OpenAiCompatibleConfig;
use HtmlTranslator\Provider\Llm\ChatMessage;
use HtmlTranslator\Provider\Llm\ChatRequest;
use HtmlTranslator\Provider\Llm\ChatRole;
use HtmlTranslator\Provider\TranslationBatch;
use HtmlTranslator\Tests\Support\CallbackProvider;
use HtmlTranslator\Tests\Support\FakeClientException;
use HtmlTranslator\Tests\Support\FakeHttpClient;
use HtmlTranslator\TranslationOptions;
use HtmlTranslator\Validation\StructureValidator;
use HtmlTranslator\Validation\Violation;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Every message that quotes text the library does not control (caller input, configuration, vendor and transport
 * errors, model output) stays bounded and on one line, so it cannot flood or forge log lines (#43).
 */
#[CoversNothing]
final class UntrustedMessagesTest extends TestCase
{
    /** About a megabyte of text with line breaks, an ANSI colour code and a forged log line. */
    private static function hostile(): string
    {
        return str_repeat("x\n\x1b[31m[error] FAKE LOG LINE\r\n", 40_000);
    }

    /**
     * @return iterable<string, array{Closure(): mixed}>
     */
    public static function throwingCalls(): iterable
    {
        $hostile = self::hostile();
        $factory = new Psr17Factory();
        $request = new ChatRequest([new ChatMessage(ChatRole::User, 'Hello')]);
        $vendorError = new FakeHttpClient(static fn(): Response => new Response(400, [], json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => $hostile]], \JSON_THROW_ON_ERROR)));
        $openAiVendorError = new FakeHttpClient(static fn(): Response => new Response(400, [], json_encode(['error' => ['message' => $hostile, 'code' => 'bad']], \JSON_THROW_ON_ERROR)));
        $transportError = new FakeHttpClient(static fn(): Response => throw new FakeClientException($hostile));

        yield 'unit id key of a batch result' => [static fn(): BatchResult => new BatchResult([$hostile => 'a'], [$hostile => 'b'])];
        yield 'correction key of a batch' => [static fn(): TranslationBatch => new TranslationBatch(LocalePair::of(null, Locale::fromString('pl')), [TranslationUnit::fromText(UnitId::fromString('u1'), UnitKind::Block, 'Hi')], [$hostile => new Correction('x', 'y')])];
        yield 'OpenAI-compatible base URL with a line break' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig('x', "https://api.example.test/v1\n" . $hostile, 'model')];
        yield 'locale' => [static fn(): Locale => Locale::fromString($hostile)];
        yield 'unit id' => [static fn(): UnitId => UnitId::fromString($hostile)];
        yield 'skipped element' => [static fn(): SkipRules => SkipRules::defaults()->withElement($hostile)];
        yield 'skip class' => [static fn(): SkipRules => SkipRules::defaults()->withClass($hostile)];
        yield 'translatable attribute' => [static fn(): TranslatableAttributes => TranslatableAttributes::defaults()->withAttribute($hostile)];
        yield 'meta name' => [static fn(): TranslatableAttributes => TranslatableAttributes::defaults()->withMetaName($hostile)];
        yield 'OpenAI-compatible output limit field' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig('x', 'https://api.example.test/v1', 'model', maxTokensField: $hostile)];
        yield 'OpenAI-compatible name' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig($hostile, '/relative', 'model')];
        yield 'OpenAI-compatible header name' => [static fn(): OpenAiCompatibleConfig => new OpenAiCompatibleConfig('x', 'https://api.example.test/v1', 'model', extraHeaders: [str_repeat('X', 1_000_000) => "bad\nvalue"])];
        yield 'Anthropic header name' => [static fn(): AnthropicConfig => new AnthropicConfig('claude-opus-5-5', 'sk-ant-1', extraHeaders: [str_repeat('X', 1_000_000) => "bad\nvalue"])];
        yield 'OpenAI-compatible vendor message' => [static fn(): mixed => new OpenAiCompatibleChatModel($openAiVendorError, $factory, $factory, OpenAiCompatibleConfig::ollama('llama3.1'))->complete($request)];
        yield 'OpenAI-compatible transport error' => [static fn(): mixed => new OpenAiCompatibleChatModel($transportError, $factory, $factory, OpenAiCompatibleConfig::ollama('llama3.1'))->complete($request)];
        yield 'Anthropic vendor message' => [static fn(): mixed => new AnthropicChatModel($vendorError, $factory, $factory, new AnthropicConfig('claude-opus-5-5', 'sk-ant-1'))->complete($request)];
        yield 'Anthropic transport error' => [static fn(): mixed => new AnthropicChatModel($transportError, $factory, $factory, new AnthropicConfig('claude-opus-5-5', 'sk-ant-1'))->complete($request)];
    }

    /**
     * @param Closure(): mixed $call
     */
    #[Test]
    #[DataProvider('throwingCalls')]
    public function it_bounds_and_keeps_on_one_line_every_audited_exception_message(Closure $call): void
    {
        try {
            $call();
        } catch (Throwable $exception) {
            self::assertInstanceOf(TranslatorException::class, $exception);
            for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
                if ($current !== $exception && (!str_starts_with($current::class, 'HtmlTranslator\\') || str_starts_with($current::class, 'HtmlTranslator\\Tests\\'))) {
                    continue; // The client's own exception is kept as it is; only the library's messages are checked.
                }

                $this->assertSafe($current->getMessage());
            }

            return;
        }

        self::fail('Expected an exception.');
    }

    #[Test]
    public function it_bounds_and_escapes_raw_markup_quoted_back_to_the_model(): void
    {
        $unit = TranslationUnit::fromText(UnitId::fromString('u1'), UnitKind::Block, 'Hello <g1>world</g1>', [InlineTag::of(1, InlineTagKind::Paired)]);

        $violations = new StructureValidator()->validate($unit, "Witaj <g1>świecie</g1> <span\nclass='" . self::hostile() . "'>")->violations();

        self::assertNotSame([], $violations);
        foreach ($violations as $violation) {
            self::assertInstanceOf(Violation::class, $violation);
            $this->assertSafe($violation->message);
        }
    }

    #[Test]
    public function it_bounds_and_keeps_on_one_line_provider_failure_reasons_in_issues(): void
    {
        $hostile = self::hostile();
        $provider = new CallbackProvider(static fn(TranslationBatch $batch): BatchResult => new BatchResult([], ['u1' => $hostile]));

        $result = new HtmlTranslator($provider)->translate('<p>Hello</p>', Locale::fromString('pl'));

        self::assertNotSame([], $result->issues());
        foreach ($result->issues() as $issue) {
            self::assertInstanceOf(Issue::class, $issue);
            $this->assertSafe($issue->message);
        }
    }

    #[Test]
    public function it_bounds_the_reasons_of_text_nodes_kept_in_the_fallback(): void
    {
        $hostile = self::hostile();
        $provider = new CallbackProvider(static function (TranslationBatch $batch) use ($hostile): BatchResult {
            $failures = [];
            foreach ($batch->units as $unit) {
                // A reason per text node, each distinct and huge.
                $failures[$unit->id->toString()] = $unit->id->toString() . $hostile;
            }

            return new BatchResult([], $failures);
        });

        $result = new HtmlTranslator($provider)->translate(
            '<p>' . str_repeat('word <b>bold</b> ', 20) . '</p>',
            Locale::fromString('pl'),
            new TranslationOptions(fallback: FallbackStrategy::TextNodesOnly, maxCorrectiveRetries: 0),
        );

        $partial = array_values(array_filter($result->issues(), static fn(Issue $issue): bool => IssueType::PartiallyTranslated === $issue->type));
        self::assertCount(1, $partial);
        $this->assertSafe($partial[0]->message);
        self::assertStringContainsString('; and 37 more', $partial[0]->message);
    }

    private function assertSafe(string $message): void
    {
        self::assertLessThan(2000, \strlen($message), 'The message quotes untrusted text without a bound.');
        self::assertDoesNotMatchRegularExpression('/[\r\n\x1B]/', $message, 'The message lets untrusted text break or colour log lines.');
    }
}
