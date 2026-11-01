<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Support;

use Closure;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Domain\LocalePair;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Domain\UnitKind;
use HtmlTranslator\Provider\Llm\ChatRequest;
use HtmlTranslator\Provider\Llm\ChatResponse;
use HtmlTranslator\Provider\Llm\FinishReason;
use HtmlTranslator\Provider\Llm\Testing\FakeChatModel;
use HtmlTranslator\Provider\Testing\PseudoLocalizationProvider;
use HtmlTranslator\Provider\TranslationBatch;
use UnexpectedValueException;

/**
 * A FakeChatModel that reads the units from a PromptBuilder request and answers in the ADR-0011 format with their
 * PseudoLocalizationProvider translation, so prompt → model → parser runs for real and the result can be compared
 * with the golden fixtures.
 */
final class PseudoLocalizingChatModel
{
    public const string MODEL = 'fake:pseudo';

    private function __construct() {}

    public static function create(): FakeChatModel
    {
        return self::refusing(static fn(array $ids): bool => false);
    }

    /**
     * Refuses a request when $refuses says so for the ids of its units (as a model refuses a batch with one
     * problematic sentence), and answers it like create() otherwise. Every answer reports the number of its units as
     * input tokens and 1 output token, so tests can count what isolating a refusal cost.
     *
     * $refuses may also return the FinishReason to refuse with, or throw (e.g. a ProviderException on the n-th call).
     *
     * @param Closure(list<string>): (bool|FinishReason) $refuses
     */
    public static function refusing(Closure $refuses, FinishReason $reason = FinishReason::Refusal): FakeChatModel
    {
        $provider = new PseudoLocalizationProvider();

        return FakeChatModel::answering(static function (ChatRequest $request) use ($provider, $refuses, $reason): ChatResponse {
            $units = self::units($request);
            $ids = array_map(static fn(TranslationUnit $unit): string => $unit->id->toString(), $units);
            $refusal = $refuses($ids);
            if (false !== $refusal) {
                return new ChatResponse('I cannot help with that.', self::MODEL, \count($units), 1, $refusal instanceof FinishReason ? $refusal : $reason);
            }

            $answer = [];
            foreach ($provider->translate(new TranslationBatch(LocalePair::of(null, Locale::fromString('pl')), $units))->translations as $id => $text) {
                $answer[] = ['id' => $id, 'text' => $text];
            }

            return new ChatResponse(json_encode(['units' => $answer], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE), self::MODEL, \count($units), 1, FinishReason::Stop);
        })->withModelId(self::MODEL);
    }

    /**
     * @return list<TranslationUnit>
     */
    private static function units(ChatRequest $request): array
    {
        $message = $request->messages[\count($request->messages) - 1] ?? throw new UnexpectedValueException('The request has no messages.');
        $decoded = json_decode($message->content, true, 4, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || !\is_array($decoded['units'] ?? null)) {
            throw new UnexpectedValueException('The user message has no "units" list.');
        }

        $units = [];
        foreach ($decoded['units'] as $entry) {
            if (!\is_array($entry) || !\is_string($entry['id'] ?? null) || !\is_string($entry['kind'] ?? null) || !\is_string($entry['text'] ?? null)) {
                throw new UnexpectedValueException('A unit needs a string id, kind and text.');
            }

            $kind = match ($entry['kind']) {
                'block' => UnitKind::Block,
                'attribute' => UnitKind::Attribute,
                'title' => UnitKind::Title,
                default => throw new UnexpectedValueException(\sprintf('Unknown unit kind "%s".', $entry['kind'])),
            };
            $units[] = TranslationUnit::fromText(UnitId::fromString($entry['id']), $kind, $entry['text']);
        }

        return $units;
    }
}
