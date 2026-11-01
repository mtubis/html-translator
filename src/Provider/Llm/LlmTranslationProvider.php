<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm;

use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Provider\BatchResult;
use HtmlTranslator\Provider\CacheableProviderInterface;
use HtmlTranslator\Provider\TokenUsage;
use HtmlTranslator\Provider\TranslationBatch;

/**
 * Translates with any chat model (ADR-0005): PromptBuilder renders the batch, including the corrections the core
 * sends, and ResponseParser reads the answer (ADR-0011). Errors of the chat model pass through unchanged.
 *
 * An answer that cannot be parsed is asked for once more, because a ProviderException on the first call of a batch
 * fails the whole translation; not when it was cut off at the token limit, where the same batch would be cut off again.
 * The second request is the same as the first, so with a deterministic model (temperature 0) it may fail the same way;
 * only the second parse error is kept, as the previous exception.
 * A batch the model refuses (content filter, refusal) fails permanently for all its units: re-sending it would be
 * refused again.
 * The result carries the tokens of both calls when it asked twice. A batch that ends in a ProviderException carries
 * them on the exception instead: the tokens of answers that could not be read, and of the first answer when the second
 * call fails (the chat model's exception is passed on with them added).
 * Cacheable (ADR-0016): the cache id names the chat model and the prompt version.
 */
final readonly class LlmTranslationProvider implements CacheableProviderInterface
{
    private PromptBuilder $promptBuilder;

    private ResponseParser $responseParser;

    /**
     * The prompt builder and response parser are internal and created here (ADR-0018).
     */
    public function __construct(
        private ChatModelInterface $chatModel,
    ) {
        $this->promptBuilder = new PromptBuilder();
        $this->responseParser = new ResponseParser();
    }

    /**
     * "llm:<model id>:<prompt version>", e.g. "llm:openai:gpt-4o-mini:2026-10.4".
     */
    public function cacheId(): string
    {
        // PromptBuilder and ResponseParser are final and have no options; if they gain any, they belong in the id.
        return \sprintf('llm:%s:%s', $this->chatModel->modelId(), PromptBuilder::VERSION);
    }

    /**
     * @throws ProviderException when the chat model fails, or its answer cannot be parsed twice in a row
     */
    public function translate(TranslationBatch $batch): BatchResult
    {
        $request = $this->promptBuilder->build($batch);
        $response = $this->chatModel->complete($request);
        $firstUsage = TokenUsage::reported($response->inputTokens, $response->outputTokens);

        try {
            return $this->read($response, $batch)->withUsage($firstUsage);
        } catch (ProviderException $exception) {
            if (FinishReason::Length === $response->finishReason) {
                throw $this->withModel($exception, $firstUsage);
            }
        }

        try {
            $response = $this->chatModel->complete($request);
        } catch (ProviderException $exception) {
            // The chat model's error passes through; only the tokens of the first answer are added to it.
            throw $firstUsage instanceof TokenUsage ? $exception->withUsage(TokenUsage::sum($firstUsage, $exception->usage)) : $exception;
        }

        $usage = TokenUsage::sum($firstUsage, TokenUsage::reported($response->inputTokens, $response->outputTokens));

        try {
            return $this->read($response, $batch)->withUsage($usage);
        } catch (ProviderException $exception) {
            throw $this->withModel($exception, $usage);
        }
    }

    /**
     * @throws ProviderException
     */
    private function read(ChatResponse $response, TranslationBatch $batch): BatchResult
    {
        $refusal = match ($response->finishReason) {
            FinishReason::ContentFilter => 'The model refused the batch (content filter).',
            FinishReason::Refusal => 'The model refused to translate the batch.',
            default => null,
        };

        if (null !== $refusal) {
            $ids = array_map(static fn(TranslationUnit $unit): string => $unit->id->toString(), $batch->units);

            return new BatchResult([], permanentFailures: array_fill_keys($ids, $refusal));
        }

        return $this->responseParser->parse($response, $batch);
    }

    /**
     * @param TokenUsage|null $usage the tokens of the answers that could not be read: they were billed all the same
     */
    private function withModel(ProviderException $exception, ?TokenUsage $usage): ProviderException
    {
        return new ProviderException(\sprintf('%s Model: %s.', $exception->getMessage(), $this->chatModel->modelId()), 0, $exception, $usage);
    }
}
