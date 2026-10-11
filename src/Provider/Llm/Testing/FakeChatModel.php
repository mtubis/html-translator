<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Llm\Testing;

use Closure;
use HtmlTranslator\Exception\LogicException;
use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Provider\Llm\ChatModelInterface;
use HtmlTranslator\Provider\Llm\ChatRequest;
use HtmlTranslator\Provider\Llm\ChatResponse;

/**
 * Test double for ChatModelInterface, no network: answers from a scripted queue (responses or exceptions to throw)
 * or through a callback, and records every request it received.
 *
 * Running out of script throws LogicException, which the core does not turn into an issue, so an under-scripted test
 * fails loudly instead of passing through a fallback.
 */
final class FakeChatModel implements ChatModelInterface
{
    /** @var list<ChatRequest> requests in the order they were received */
    public private(set) array $requests = [];

    private string $modelId = 'fake:model';

    /**
     * @param list<ChatResponse|ProviderException> $queue
     * @param (Closure(ChatRequest): ChatResponse)|null $answer
     */
    private function __construct(
        private array $queue,
        private readonly ?Closure $answer,
    ) {}

    /**
     * Answers each request with the next item; a ProviderException is thrown instead of answering.
     */
    public static function withResponses(ChatResponse|ProviderException ...$responses): self
    {
        return new self(array_values($responses), null);
    }

    /**
     * @param Closure(ChatRequest): ChatResponse $answer
     */
    public static function answering(Closure $answer): self
    {
        return new self([], $answer);
    }

    public function withModelId(string $modelId): self
    {
        $clone = clone $this;
        $clone->modelId = $modelId;

        return $clone;
    }

    public function modelId(): string
    {
        return $this->modelId;
    }

    public function complete(ChatRequest $request): ChatResponse
    {
        $this->requests[] = $request;

        if ($this->answer instanceof Closure) {
            return ($this->answer)($request);
        }

        $next = array_shift($this->queue)
            ?? throw new LogicException(\sprintf('FakeChatModel has no scripted response left for request #%d.', \count($this->requests)));

        if ($next instanceof ProviderException) {
            throw $next;
        }

        return $next;
    }
}
