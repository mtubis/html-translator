<?php

declare(strict_types=1);

namespace HtmlTranslator\Validation;

use HtmlTranslator\Domain\InlineTagKind;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitKind;
use HtmlTranslator\Domain\UnitText;
use HtmlTranslator\Domain\UnitTextToken;
use HtmlTranslator\Domain\UnitTextTokenKind;
use HtmlTranslator\Exception\UntrustedText;

/**
 * Checks that a translation keeps the structure of its source unit (ADR-0003, ARCHITECTURE §6.5).
 *
 * Invalid UTF-8 is reported alone (nothing else can be checked reliably). Otherwise violations come in a fixed
 * order: empty translation; problems in text order (raw markup, tags in a plain-text unit, unknown tag, wrong
 * tag form, closing tag without opening, overlapping tags); unclosed tags in opening order; duplicates and
 * missing tags by ascending number. Each kind of tag problem is reported once per tag number.
 */
final readonly class StructureValidator
{
    /** Raw markup is quoted in messages up to this many characters, ellipsis included. */
    private const int SNIPPET_LENGTH = 30;

    public function validate(TranslationUnit $source, string $translated): ValidationResult
    {
        if (!mb_check_encoding($translated, 'UTF-8')) {
            return new ValidationResult([new Violation(ViolationType::InvalidEncoding, 'Rewrite the translation as valid UTF-8 text.')]);
        }

        $tokens = UnitText::tokenize($translated);
        $violations = [];

        if (!UnitText::hasTranslatableCharacter($this->plainText($tokens))) {
            $violations[] = new Violation(ViolationType::EmptyTranslation, 'Translate the text; the translation must not be empty.');
        }

        $violations = UnitKind::Block === $source->kind
            ? [...$violations, ...$this->checkTags($source, $tokens)]
            : [...$violations, ...$this->checkPlainText($tokens)];

        return new ValidationResult($violations);
    }

    /**
     * @param list<UnitTextToken> $tokens
     *
     * @return list<Violation>
     */
    private function checkPlainText(array $tokens): array
    {
        $violations = [];
        $tagReported = false;

        foreach ($tokens as $token) {
            if (UnitTextTokenKind::RawMarkup === $token->kind) {
                $violations[] = $this->rawMarkup($token);
            } elseif (UnitTextTokenKind::Text !== $token->kind && !$tagReported) {
                $violations[] = new Violation(ViolationType::TagsInPlainText, 'Remove all tags such as <g1>, </g1> and <x1/>: this text is plain text.');
                $tagReported = true;
            }
        }

        return $violations;
    }

    /**
     * @param list<UnitTextToken> $tokens
     *
     * @return list<Violation>
     */
    private function checkTags(TranslationUnit $source, array $tokens): array
    {
        $expected = [];
        foreach ($source->inlineTags as $tag) {
            $expected[$tag->number] = $tag->kind;
        }

        $violations = [];
        $reported = [];
        $occurrences = [];
        $nesting = new TagNesting();

        foreach ($tokens as $token) {
            if (UnitTextTokenKind::RawMarkup === $token->kind) {
                $violations[] = $this->rawMarkup($token);

                continue;
            }

            $number = $token->number;
            if (null === $number) {
                continue;
            }

            $expectedKind = $expected[$number] ?? null;
            if (null === $expectedKind) {
                $violations = $this->reportOnce($violations, $reported, ViolationType::UnknownTag, $number, \sprintf('Remove %s: there is no tag %d in the source text.', $this->spelling($token), $number));

                continue;
            }

            $occurrences[$number] ??= 0;

            if ((InlineTagKind::Paired === $expectedKind) === (UnitTextTokenKind::Void === $token->kind)) {
                $wrongKind = InlineTagKind::Paired === $expectedKind ? InlineTagKind::Void : InlineTagKind::Paired;
                $violations = $this->reportOnce($violations, $reported, ViolationType::WrongTagForm, $number, \sprintf('Write tag %d as %s, not as %s.', $number, $this->form($number, $expectedKind), $this->form($number, $wrongKind)));

                continue;
            }

            if (UnitTextTokenKind::Close === $token->kind) {
                $overlapping = $nesting->close($number);

                if (null === $overlapping) {
                    $violations = $this->reportOnce($violations, $reported, ViolationType::UnexpectedClosingTag, $number, \sprintf('Remove </g%1$d>: it has no matching <g%1$d>.', $number));
                } elseif ([] !== $overlapping) {
                    $violations[] = new Violation(ViolationType::ImproperNesting, \sprintf(
                        'Close %s before </g%d>: tags must not overlap.',
                        implode(' and ', array_map(static fn(int $inner): string => \sprintf('<g%d>', $inner), $overlapping)),
                        $number,
                    ));
                }

                continue;
            }

            ++$occurrences[$number];

            if (UnitTextTokenKind::Open === $token->kind) {
                $nesting->open($number);
            }
        }

        foreach ($nesting->unclosed() as $number) {
            $violations = $this->reportOnce($violations, $reported, ViolationType::UnclosedTag, $number, \sprintf('Close <g%1$d> with </g%1$d>.', $number));
        }

        ksort($occurrences);
        foreach ($occurrences as $number => $count) {
            if ($count > 1) {
                $violations[] = new Violation(ViolationType::DuplicateTag, \sprintf('Use %s only once; it appears %d times.', $this->form($number, $expected[$number] ?? InlineTagKind::Void), $count));
            }
        }

        ksort($expected);
        foreach ($expected as $number => $kind) {
            if (!isset($occurrences[$number])) {
                $violations[] = new Violation(ViolationType::MissingTag, \sprintf('Add %s: every tag from the source text must appear exactly once.', $this->form($number, $kind)));
            }
        }

        return $violations;
    }

    /**
     * @param list<Violation> $violations
     * @param array<string, array<int, true>> $reported violation type name → tag numbers already reported
     *
     * @param-out array<string, array<int, true>> $reported
     *
     * @return list<Violation>
     */
    private function reportOnce(array $violations, array &$reported, ViolationType $type, int $number, string $message): array
    {
        if (!isset($reported[$type->name][$number])) {
            $violations[] = new Violation($type, $message);
            $reported[$type->name][$number] = true;
        }

        return $violations;
    }

    private function rawMarkup(UnitTextToken $token): Violation
    {
        // Model output, quoted back to the model and into issues: bounded and on one line.
        $snippet = UntrustedText::quote($token->text, self::SNIPPET_LENGTH);

        return new Violation(ViolationType::RawMarkup, \sprintf('Remove the markup "%s": use only the tags from the source text, and write a literal "<" as &lt;.', $snippet));
    }

    private function spelling(UnitTextToken $token): string
    {
        return match ($token->kind) {
            UnitTextTokenKind::Open => \sprintf('<g%d>', $token->number),
            UnitTextTokenKind::Close => \sprintf('</g%d>', $token->number),
            default => \sprintf('<x%d/>', $token->number),
        };
    }

    private function form(int $number, InlineTagKind $kind): string
    {
        return InlineTagKind::Paired === $kind ? \sprintf('<g%1$d>…</g%1$d>', $number) : \sprintf('<x%d/>', $number);
    }

    /**
     * @param list<UnitTextToken> $tokens
     */
    private function plainText(array $tokens): string
    {
        $plain = '';
        foreach ($tokens as $token) {
            if (UnitTextTokenKind::Text === $token->kind) {
                $plain .= $token->text;
            }
        }

        return $plain;
    }
}
