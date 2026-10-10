<?php

declare(strict_types=1);

namespace HtmlTranslator;

use Closure;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Domain\LocalePair;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Domain\UnitKind;
use HtmlTranslator\Domain\UnitText;
use HtmlTranslator\Domain\UnitTextToken;
use HtmlTranslator\Domain\UnitTextTokenKind;
use HtmlTranslator\Exception\InvalidArgumentException;
use HtmlTranslator\Exception\InvalidHtmlException;
use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Exception\StructureValidationException;
use HtmlTranslator\Html\HtmlLoader;
use HtmlTranslator\Html\Reassembler;
use HtmlTranslator\Html\Segmenter;
use HtmlTranslator\Html\SkipRules;
use HtmlTranslator\Html\TranslatableAttributes;
use HtmlTranslator\Provider\Correction;
use HtmlTranslator\Provider\TranslationBatch;
use HtmlTranslator\Provider\TranslationProviderInterface;
use HtmlTranslator\Validation\StructureValidator;
use Psr\Log\LoggerInterface;

/**
 * Entry point: translates an HTML document or fragment and keeps its structure (ARCHITECTURE §4–§6).
 *
 * Pipeline: load → segment → batches → provider → validation with corrective retries → fallback → reassembly.
 * Partial failures do not throw (ADR-0004): they become issues on the result, resolved by the FallbackStrategy.
 *
 * @phpstan-type Failure array{unit: TranslationUnit, type: IssueType, detail: string, permanent: bool, cause: ?ProviderException}
 */
final readonly class HtmlTranslator
{
    private SkipRules $skipRules;

    private TranslatableAttributes $translatableAttributes;

    public function __construct(
        private TranslationProviderInterface $provider,
        private ?LoggerInterface $logger = null,
        private HtmlLoader $loader = new HtmlLoader(),
        private Segmenter $segmenter = new Segmenter(),
        private StructureValidator $validator = new StructureValidator(),
        private Reassembler $reassembler = new Reassembler(),
        ?SkipRules $skipRules = null,
        ?TranslatableAttributes $translatableAttributes = null,
    ) {
        $this->skipRules = $skipRules ?? SkipRules::defaults();
        $this->translatableAttributes = $translatableAttributes ?? TranslatableAttributes::defaults();
    }

    /**
     * @throws InvalidHtmlException when the input is empty or nested too deeply
     * @throws InvalidArgumentException when source and target are the same locale
     * @throws ProviderException when the provider cannot handle the first call of a batch at all, or when a unit
     *                           was refused or left out and the fallback is FallbackStrategy::Throw
     * @throws StructureValidationException when a unit's translation stays invalid and the fallback is FallbackStrategy::Throw
     */
    public function translate(string $html, Locale $target, ?TranslationOptions $options = null): TranslatedDocument
    {
        $options ??= new TranslationOptions();
        $started = hrtime(true);
        $locales = LocalePair::of($options->source, $target);
        $segmented = $this->segmenter->segment($this->loader->load($html), $this->skipRules, $this->translatableAttributes);
        $units = $segmented->units();
        $batches = array_chunk($units, $options->batchSize);

        $translations = [];
        $issues = [];
        $complete = true;
        $retries = 0;
        $unitsDone = 0;

        foreach ($batches as $index => $batchUnits) {
            [$translated, $failures, $batchRetries] = $this->translateWithRetries($locales, $batchUnits, $options->maxCorrectiveRetries);
            $translations += $translated;
            $retries += $batchRetries;

            if ([] !== $failures) {
                [$fallbackTranslations, $fallbackIssues, $fallbackComplete] = $this->applyFallback($locales, $failures, $options);
                $translations += $fallbackTranslations;
                array_push($issues, ...$fallbackIssues);
                $complete = $complete && $fallbackComplete;
            }

            $unitsDone += \count($batchUnits);
            if ($options->onProgress instanceof Closure) {
                ($options->onProgress)(new Progress($index + 1, \count($batches), $unitsDone, \count($units)));
            }
        }

        $this->reassembler->apply($segmented, $translations, $target);

        foreach ($issues as $issue) {
            $this->logger?->warning($issue->message, ['unit' => $issue->unitId->toString(), 'type' => $issue->type->name]);
        }

        return new TranslatedDocument(
            $segmented->document()->serialize(),
            $complete,
            $issues,
            new TranslationStats(\count($units), \count($batches), $retries, (hrtime(true) - $started) / 1_000_000_000),
        );
    }

    /**
     * Sends the units, then sends the failed ones again up to $maxRetries times, with feedback for invalid and
     * missing ones; refused units are re-sent without feedback, permanently refused ones are not re-sent at all.
     * A ProviderException on the first call propagates; on a retry it turns the pending units into provider
     * failures (keeping the exception as their cause), so translations that already succeeded are kept (ADR-0004).
     *
     * @param list<TranslationUnit> $units
     *
     * @return array{array<string, string>, array<string, Failure>, int} valid translations, the last failure of
     *                                                                   each failed unit in document order, units retried
     */
    private function translateWithRetries(LocalePair $locales, array $units, int $maxRetries): array
    {
        $pending = [];
        foreach ($units as $unit) {
            $pending[$unit->id->toString()] = $unit;
        }

        $translations = [];
        $failures = [];
        $permanent = [];
        $corrections = [];
        $retries = 0;

        for ($attempt = 0; [] !== $pending && $attempt <= $maxRetries; ++$attempt) {
            if ($attempt > 0) {
                $retries += \count($pending);
            }

            try {
                $result = $this->provider->translate(new TranslationBatch($locales, array_values($pending), $corrections));
            } catch (ProviderException $exception) {
                if (0 === $attempt) {
                    throw $exception;
                }

                $failures = array_map(fn(TranslationUnit $unit): array => $this->failure($unit, IssueType::ProviderFailure, $exception->getMessage(), cause: $exception), $pending);

                break;
            }

            $corrections = [];
            $failures = [];

            foreach ($pending as $id => $unit) {
                if (isset($result->permanentFailures[$id])) {
                    $permanent[$id] = $this->failure($unit, IssueType::ProviderFailure, $result->permanentFailures[$id], permanent: true);

                    continue;
                }

                if (isset($result->failures[$id])) {
                    $failures[$id] = $this->failure($unit, IssueType::ProviderFailure, $result->failures[$id]);

                    continue;
                }

                $translated = $result->translations[$id] ?? null;
                if (null === $translated) {
                    $failures[$id] = $this->failure($unit, IssueType::MissingTranslation, '');
                    $corrections[$id] = Correction::forMissingUnit();

                    continue;
                }

                $validation = $this->validator->validate($unit, $translated);
                if ($validation->isValid()) {
                    $translations[$id] = $translated;

                    continue;
                }

                $failures[$id] = $this->failure($unit, IssueType::InvalidStructure, $validation->describe());
                $corrections[$id] = new Correction($translated, $validation->describe());
            }

            $pending = array_intersect_key($pending, $failures);
        }

        $failed = [];
        foreach ($units as $unit) {
            $id = $unit->id->toString();
            $failure = $permanent[$id] ?? $failures[$id] ?? null;
            if (null !== $failure) {
                $failed[$id] = $failure;
            }
        }

        return [$translations, $failed, $retries];
    }

    /**
     * @return Failure
     */
    private function failure(TranslationUnit $unit, IssueType $type, string $detail, bool $permanent = false, ?ProviderException $cause = null): array
    {
        return ['unit' => $unit, 'type' => $type, 'detail' => $detail, 'permanent' => $permanent, 'cause' => $cause];
    }

    /**
     * @param array<string, Failure> $failures
     *
     * @return array{array<string, string>, list<Issue>, bool} translations, issues, whether every unit is translated
     */
    private function applyFallback(LocalePair $locales, array $failures, TranslationOptions $options): array
    {
        $issues = [];
        $failedUnits = [];

        foreach ($failures as $id => $failure) {
            $message = $this->failureMessage($id, $failure['type'], $failure['detail'], $failure['permanent'], $options->maxCorrectiveRetries);

            if (FallbackStrategy::Throw === $options->fallback) {
                // The provider's own exception (status, vendor code) stays reachable as the previous one.
                throw IssueType::InvalidStructure === $failure['type'] ? new StructureValidationException($message) : new ProviderException($message, 0, $failure['cause']);
            }

            if (FallbackStrategy::TextNodesOnly === $options->fallback) {
                $message .= "\nIts text nodes were sent for translation separately.";
            }

            $issues[] = new Issue($failure['unit']->id, $failure['type'], $message);
            $failedUnits[] = $failure['unit'];
        }

        if (FallbackStrategy::KeepOriginal === $options->fallback) {
            return [[], $issues, false];
        }

        [$translations, $partialIssues] = $this->translateTextNodes($locales, $failedUnits);

        return [$translations, [...$issues, ...$partialIssues], [] === $partialIssues];
    }

    private function failureMessage(string $id, IssueType $type, string $detail, bool $permanent, int $maxRetries): string
    {
        return match ($type) {
            IssueType::ProviderFailure => \sprintf('The provider could not translate unit "%s"%s: %s', $id, $permanent ? ' (permanent failure)' : '', $detail),
            IssueType::MissingTranslation => \sprintf('The provider returned no translation for unit "%s".', $id),
            IssueType::InvalidStructure, IssueType::PartiallyTranslated => \sprintf(
                "The translation of unit \"%s\" is still invalid after %d corrective %s:\n%s",
                $id,
                $maxRetries,
                1 === $maxRetries ? 'retry' : 'retries',
                $detail,
            ),
        };
    }

    /**
     * TextNodesOnly: each text node of a unit becomes its own plain-text unit ("u3-1", "u3-2", …), all sent in one
     * call without retries; the original tags are put back around the results, so the structure is always valid.
     * A text node that fails keeps its original text, also when the provider throws ProviderException.
     *
     * @param list<TranslationUnit> $units
     *
     * @return array{array<string, string>, list<Issue>}
     */
    private function translateTextNodes(LocalePair $locales, array $units): array
    {
        $plans = [];
        $pieces = [];

        foreach ($units as $unit) {
            $plan = [];
            $number = 0;
            foreach (UnitText::tokenize($unit->text) as $token) {
                $piece = null;
                if (UnitTextTokenKind::Text === $token->kind && UnitText::hasTranslatableCharacter($token->text)) {
                    $piece = TranslationUnit::fromText(UnitId::fromString(\sprintf('%s-%d', $unit->id, ++$number)), UnitKind::Block, UnitText::escape($token->text));
                    $pieces[] = $piece;
                }

                $plan[] = [$token, $piece];
            }

            $plans[] = [$unit, $plan];
        }

        $result = null;
        $providerError = 'no translation';
        try {
            $result = [] === $pieces ? null : $this->provider->translate(new TranslationBatch($locales, $pieces));
        } catch (ProviderException $exception) {
            $providerError = $exception->getMessage();
        }

        $translations = [];
        $issues = [];
        foreach ($plans as [$unit, $plan]) {
            $text = '';
            $total = 0;
            $reasons = [];

            foreach ($plan as [$token, $piece]) {
                if (!$piece instanceof TranslationUnit) {
                    $text .= $this->spell($token);

                    continue;
                }

                ++$total;
                $pieceId = $piece->id->toString();
                $translated = $result?->translations[$pieceId] ?? null;
                $reason = null === $translated
                    ? ($result?->failures[$pieceId] ?? $result?->permanentFailures[$pieceId] ?? $providerError)
                    : ($this->validator->validate($piece, $translated)->isValid() ? null : 'invalid translation');

                if (null === $reason) {
                    $text .= $piece->leadingWhitespace . $translated . $piece->trailingWhitespace;
                } else {
                    $text .= $this->spell($token);
                    $reasons[] = $reason;
                }
            }

            $translations[$unit->id->toString()] = $text;

            if ([] !== $reasons) {
                $issues[] = new Issue($unit->id, IssueType::PartiallyTranslated, \sprintf(
                    'Unit "%s": %d of %d text nodes kept their original text (%s).',
                    $unit->id,
                    \count($reasons),
                    $total,
                    implode('; ', array_unique($reasons)),
                ));
            }
        }

        return [$translations, $issues];
    }

    /**
     * A source token back in unit text format: text escaped (ADR-0008), tags as they were.
     */
    private function spell(UnitTextToken $token): string
    {
        return UnitTextTokenKind::Text === $token->kind ? UnitText::escape($token->text) : $token->text;
    }
}
