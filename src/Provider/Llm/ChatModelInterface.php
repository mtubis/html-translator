<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm;

use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\ProviderException;

/**
 * Port for LLM endpoints (ADR-0005): send a conversation, get the answer. Only LlmTranslationProvider uses it; prompts
 * and parsing are shared, so a vendor adapter is just a mapping to the vendor's HTTP API on PSR-18/17 (ADR-0002).
 *
 * Adapters
 * - may retry transient transport failures themselves (429/5xx with backoff, ARCHITECTURE §6.8; use
 *   Provider\Llm\Bridge\Retrier); the core never retries a call that threw;
 * - throw ProviderException for every failure, with ProviderException::fromHttpResponse() when there is an HTTP
 *   status, and wrap transport and decoding errors in it;
 * - never put API keys, tokens or other credentials into exception messages or traces (#[\SensitiveParameter]);
 * - map the vendor's finish reason to FinishReason;
 * - must pass Provider\Llm\Testing\ChatModelContractTestCase.
 */
interface ChatModelInterface
{
    /**
     * The configured model, stable across calls and known before any call (e.g. "openai:gpt-4o-mini"); part of the
     * translation cache key. ChatResponse::$model may differ (vendors often report a dated snapshot).
     */
    public function modelId(): string;

    /**
     * @throws ProviderException when the model cannot answer
     * @throws InvalidArgumentException when the request uses a setting the vendor API does not support (e.g. a
     *                                  temperature above 1 or a prefilled answer); nothing is sent
     */
    public function complete(ChatRequest $request): ChatResponse;
}
