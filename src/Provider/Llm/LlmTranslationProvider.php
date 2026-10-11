<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm;

use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Provider\BatchResult;
use HtmlTranslator\Provider\TranslationBatch;
use HtmlTranslator\Provider\TranslationProviderInterface;

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
 */
final readonly class LlmTranslationProvider implements TranslationProviderInterface
{
    public function __construct(
        private ChatModelInterface $chatModel,
        private PromptBuilder $promptBuilder = new PromptBuilder(),
        private ResponseParser $responseParser = new ResponseParser(),
    ) {}

    /**
     * @throws ProviderException when the chat model fails, or its answer cannot be parsed twice in a row
     */
    public function translate(TranslationBatch $batch): BatchResult
    {
        $request = $this->promptBuilder->build($batch);
        $response = $this->chatModel->complete($request);

        try {
            return $this->read($response, $batch);
        } catch (ProviderException $exception) {
            if (FinishReason::Length === $response->finishReason) {
                throw $this->withModel($exception);
            }
        }

        // Outside the try: errors of the chat model pass through unchanged, also on the second call.
        $response = $this->chatModel->complete($request);

        try {
            return $this->read($response, $batch);
        } catch (ProviderException $exception) {
            throw $this->withModel($exception);
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

    private function withModel(ProviderException $exception): ProviderException
    {
        return new ProviderException(\sprintf('%s Model: %s.', $exception->getMessage(), $this->chatModel->modelId()), 0, $exception);
    }
}
