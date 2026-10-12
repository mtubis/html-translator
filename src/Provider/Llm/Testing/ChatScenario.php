<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm\Testing;

use HtmlTranslator\Provider\Llm\FinishReason;

/**
 * How the far side of a chat model behaves in a contract test. Each adapter reproduces the scenario with its own fake
 * transport (e.g. a PSR-18 client building a vendor-shaped response from these values); the contract assertions stay
 * the same for all adapters.
 *
 * Fields that do not apply to a kind are null.
 */
final readonly class ChatScenario
{
    private function __construct(
        public ChatScenarioKind $kind,
        public ?string $content = null,
        public ?string $model = null,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?FinishReason $finishReason = null,
        public ?int $statusCode = null,
        public ?string $vendorCode = null,
        public ?string $errorMessage = null,
        public bool $jsonErrorBody = true,
    ) {}

    /**
     * @param string|null $model null: the server reports no model, the adapter falls back to its configured one
     */
    public static function success(string $content, ?string $model, ?int $inputTokens = null, ?int $outputTokens = null, ?FinishReason $finishReason = FinishReason::Stop): self
    {
        return new self(ChatScenarioKind::Success, content: $content, model: $model, inputTokens: $inputTokens, outputTokens: $outputTokens, finishReason: $finishReason);
    }

    /**
     * The server answers with an error status. With $jsonErrorBody the body is the vendor's JSON error carrying
     * $vendorCode and $errorMessage; otherwise it is $errorMessage as raw text (e.g. an HTML page from a proxy).
     * The error message may contain the API key (some vendors echo it): adapters must not pass it through verbatim.
     */
    public static function httpError(int $statusCode, ?string $vendorCode, string $errorMessage, bool $jsonErrorBody = true): self
    {
        return new self(ChatScenarioKind::HttpError, statusCode: $statusCode, vendorCode: $vendorCode, errorMessage: $errorMessage, jsonErrorBody: $jsonErrorBody);
    }

    public static function malformedBody(): self
    {
        return new self(ChatScenarioKind::MalformedBody);
    }

    public static function unexpectedShape(): self
    {
        return new self(ChatScenarioKind::UnexpectedShape);
    }

    /**
     * The fake transport must fail with $errorMessage, which contains the API key (as a request URI or header dump
     * would): adapters must not chain that message verbatim.
     */
    public static function transportError(string $errorMessage): self
    {
        return new self(ChatScenarioKind::TransportError, errorMessage: $errorMessage);
    }

    /**
     * The fake transport must throw a PSR-18 NetworkExceptionInterface with $errorMessage that holds the request it
     * received: adapters must not keep it as the cause, since the request carries the credential headers.
     */
    public static function networkError(string $errorMessage): self
    {
        return new self(ChatScenarioKind::NetworkError, errorMessage: $errorMessage);
    }
}
