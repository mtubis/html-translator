<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm;

use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Exception\UntrustedText;
use HtmlTranslator\Provider\BatchResult;
use HtmlTranslator\Provider\CacheableProviderInterface;
use HtmlTranslator\Provider\TokenUsage;
use HtmlTranslator\Provider\TranslationBatch;

/**
 * Translates with any chat model (ADR-0005): PromptBuilder renders the batch, including the corrections the core
 * sends, and ResponseParser reads the answer (ADR-0011). Errors of the chat model pass through
 * unchanged, except while isolating a refusal (see below).
 *
 * An answer that cannot be parsed is asked for once more, because a ProviderException on the first call of a batch
 * fails the whole translation; not when it was cut off at the token limit, where the same batch would be cut off again.
 * The second request is the same as the first, so with a deterministic model (temperature 0) it may fail the same way;
 * only the second parse error is kept, as the previous exception.
 * A batch the model refuses (content filter, refusal) is split in halves and sent again, breadth first, until the
 * refused units are isolated (ADR-0019): only they fail permanently, re-sending them would be refused again. At most
 * 2 × ⌈log2 n⌉ further calls are made for a batch of n units; parts still refused when the budget is spent fail as a
 * whole. Parts are not asked once more: units of a part whose answer cannot be read are left to the corrective
 * retries, and when the chat model fails while isolating, the units not yet resolved fail as refused.
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
     *
     * @param bool $isolateRefusals split a refused batch to find the refused units (ADR-0019); costs up to
     *                              2 × ⌈log2 n⌉ further calls for a batch of n units
     */
    public function __construct(
        private ChatModelInterface $chatModel,
        private bool $isolateRefusals = true,
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
            return $this->read($response, $batch, $firstUsage);
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
            return $this->read($response, $batch, $usage);
        } catch (ProviderException $exception) {
            throw $this->withModel($exception, $usage);
        }
    }

    /**
     * @throws ProviderException when the answer cannot be parsed
     */
    private function read(ChatResponse $response, TranslationBatch $batch, ?TokenUsage $usage): BatchResult
    {
        $refusal = $this->refusal($response);

        return $refusal instanceof FinishReason ? $this->refused($batch, $refusal, $usage) : $this->responseParser->parse($response, $batch)->withUsage($usage);
    }

    private function refusal(ChatResponse $response): ?FinishReason
    {
        return FinishReason::ContentFilter === $response->finishReason || FinishReason::Refusal === $response->finishReason ? $response->finishReason : null;
    }

    private function refused(TranslationBatch $batch, FinishReason $refusal, ?TokenUsage $usage): BatchResult
    {
        if (!$this->isolateRefusals || \count($batch->units) < 2) {
            return new BatchResult([], permanentFailures: array_fill_keys($this->ids($batch->units), $this->reason($refusal, 'the batch')), usage: $usage);
        }

        return $this->isolate($batch, $refusal, $usage);
    }

    /**
     * Breadth first, so the budget is shared by every refused part instead of being spent on the first one.
     */
    private function isolate(TranslationBatch $batch, FinishReason $refusal, ?TokenUsage $usage): BatchResult
    {
        $budget = 2 * (int) ceil(log(\count($batch->units), 2));
        $translations = [];
        $failures = [];
        $permanent = [];
        /** @var list<array{list<TranslationUnit>, FinishReason}> $refused parts still refused, in the order they were found */
        $refused = [[$batch->units, $refusal]];

        while ([] !== $refused) {
            [$part, $reason] = array_shift($refused);
            if (1 === \count($part) || $budget < 2) {
                $permanent += array_fill_keys($this->ids($part), $this->reason($reason, 1 === \count($part) ? 'this unit' : 'a part of the batch with this unit (the limit of calls to find the refused unit was reached)'));

                continue;
            }

            $budget -= 2;
            // The first half takes the odd unit.
            $size = intdiv(\count($part) + 1, 2);
            $halves = array_chunk($part, max(1, $size));
            foreach ($halves as $index => $half) {
                $partBatch = $this->part($batch, $half);

                try {
                    $response = $this->chatModel->complete($this->promptBuilder->build($partBatch));
                } catch (ProviderException $exception) {
                    // Never worse than not isolating: what is not resolved yet fails as refused, nothing is aborted.
                    // Other exceptions break the ChatModelInterface contract and pass through.
                    $usage = TokenUsage::sum($usage, $exception->usage);
                    $stopped = ' Finding the refused unit stopped: ' . UntrustedText::quote($exception->getMessage());
                    foreach ([[array_merge(...\array_slice($halves, $index)), $reason], ...$refused] as [$unresolved, $unresolvedReason]) {
                        $permanent += array_fill_keys($this->ids($unresolved), $this->reason($unresolvedReason, 'a part of the batch with this unit') . $stopped);
                    }

                    return new BatchResult($translations, $failures, $permanent, $usage);
                }

                $usage = TokenUsage::sum($usage, TokenUsage::reported($response->inputTokens, $response->outputTokens));
                $halfRefusal = $this->refusal($response);
                if ($halfRefusal instanceof FinishReason) {
                    $refused[] = [$half, $halfRefusal];

                    continue;
                }

                try {
                    $result = $this->responseParser->parse($response, $partBatch);
                } catch (ProviderException $exception) {
                    $failures += array_fill_keys($this->ids($half), \sprintf('%s Model: %s.', $exception->getMessage(), $this->chatModel->modelId()));

                    continue;
                }

                $translations += $result->translations;
                $failures += $result->failures;
                $permanent += $result->permanentFailures;
            }
        }

        return new BatchResult($translations, $failures, $permanent, $usage);
    }

    /**
     * @param non-empty-list<TranslationUnit> $units
     */
    private function part(TranslationBatch $batch, array $units): TranslationBatch
    {
        $ids = $this->ids($units);

        return new TranslationBatch(
            $batch->locales,
            $units,
            array_intersect_key($batch->corrections, array_flip($ids)),
            $batch->profile,
            $batch->context->select(array_combine($ids, $ids)),
        );
    }

    private function reason(FinishReason $refusal, string $what): string
    {
        return FinishReason::ContentFilter === $refusal ? \sprintf('The model refused %s (content filter).', $what) : \sprintf('The model refused to translate %s.', $what);
    }

    /**
     * @param list<TranslationUnit> $units
     *
     * @return list<string>
     */
    private function ids(array $units): array
    {
        return array_map(static fn(TranslationUnit $unit): string => $unit->id->toString(), $units);
    }

    /**
     * @param TokenUsage|null $usage the tokens of the answers that could not be read: they were billed all the same
     */
    private function withModel(ProviderException $exception, ?TokenUsage $usage): ProviderException
    {
        return new ProviderException(\sprintf('%s Model: %s.', $exception->getMessage(), $this->chatModel->modelId()), 0, $exception, $usage);
    }
}
