<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Support;

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
        $provider = new PseudoLocalizationProvider();

        return FakeChatModel::answering(static function (ChatRequest $request) use ($provider): ChatResponse {
            $batch = new TranslationBatch(LocalePair::of(null, Locale::fromString('pl')), self::units($request));
            $answer = [];
            foreach ($provider->translate($batch)->translations as $id => $text) {
                $answer[] = ['id' => $id, 'text' => $text];
            }

            return new ChatResponse(json_encode(['units' => $answer], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE), self::MODEL, finishReason: FinishReason::Stop);
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
