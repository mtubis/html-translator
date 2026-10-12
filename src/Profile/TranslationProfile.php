<?php

declare(strict_types=1);

namespace HtmlTranslator\Profile;

use HtmlTranslator\Exception\InvalidArgumentException;

/**
 * Everything that steers how text is translated (ARCHITECTURE §3): domain, formality, term policy, glossary,
 * do-not-translate terms and free-form instructions from the developer. Immutable; withers return a new profile.
 *
 * The default profile sets nothing, so the model decides; LlmTranslationProvider renders only what is set
 * (Formality::Default and TermPolicy::TranslateAll add no instruction). A term may be in the glossary or in the
 * do-not-translate list, not in both.
 */
final readonly class TranslationProfile
{
    public const int MAX_DOMAIN_LENGTH = 200;

    public const int MAX_INSTRUCTIONS_LENGTH = 2000;

    public const int MAX_DO_NOT_TRANSLATE_TERMS = 500;

    /** @var list<string> */
    public array $doNotTranslate;

    public ?string $domain;

    public ?string $instructions;

    /**
     * @param list<string> $doNotTranslate
     *
     * @throws InvalidArgumentException when a text field is blank, not valid UTF-8 or too long, a single-line field
     *                                  contains line breaks, do-not-translate terms repeat case-insensitively or are
     *                                  too many, or a term is both in the glossary and in the do-not-translate list
     */
    private function __construct(
        ?string $domain,
        public Formality $formality,
        public TermPolicy $termPolicy,
        public Glossary $glossary,
        array $doNotTranslate,
        ?string $instructions,
    ) {
        $this->domain = null === $domain ? null : ProfileText::normalize($domain, 'Domain', self::MAX_DOMAIN_LENGTH, singleLine: true);
        $this->instructions = null === $instructions ? null : ProfileText::normalize($instructions, 'Instructions', self::MAX_INSTRUCTIONS_LENGTH, singleLine: false);
        $this->doNotTranslate = $this->doNotTranslateTerms($doNotTranslate);

        // Both say how a term is translated; with the same term in both, the model would get contradicting rules.
        $doNotTranslate = array_flip(array_map(mb_strtolower(...), $this->doNotTranslate));
        foreach ($glossary->entries() as $entry) {
            if (isset($doNotTranslate[mb_strtolower($entry->source)])) {
                throw new InvalidArgumentException(\sprintf('"%s" is both a glossary term and a do-not-translate term; keep it in one of them.', $entry->source));
            }
        }
    }

    public static function default(): self
    {
        return new self(null, Formality::Default, TermPolicy::TranslateAll, Glossary::empty(), [], null);
    }

    /**
     * Software and technical documentation; keeps the technical terms professionals use untranslated.
     */
    public static function technical(): self
    {
        return new self('software and technical documentation', Formality::Default, TermPolicy::PreferOriginalTechnicalTerms, Glossary::empty(), [], null);
    }

    /**
     * Marketing copy; adapts idioms and tone instead of translating literally.
     */
    public static function marketing(): self
    {
        return new self(
            'marketing copy',
            Formality::Default,
            TermPolicy::TranslateAll,
            Glossary::empty(),
            [],
            'Adapt idioms and tone for the target audience instead of translating literally. Keep the length of the text close to the source.',
        );
    }

    /**
     * Legal texts; formal, precise, no paraphrasing.
     */
    public static function legal(): self
    {
        return new self(
            'legal texts',
            Formality::Formal,
            TermPolicy::TranslateAll,
            Glossary::empty(),
            [],
            'Translate precisely. Do not paraphrase or summarise. Translate defined terms consistently.',
        );
    }

    /**
     * @param string|null $domain e.g. "medical devices"; null removes it
     *
     * @throws InvalidArgumentException when the domain is blank, not valid UTF-8 or longer than MAX_DOMAIN_LENGTH characters
     */
    public function withDomain(?string $domain): self
    {
        return new self($domain, $this->formality, $this->termPolicy, $this->glossary, $this->doNotTranslate, $this->instructions);
    }

    public function withFormality(Formality $formality): self
    {
        return new self($this->domain, $formality, $this->termPolicy, $this->glossary, $this->doNotTranslate, $this->instructions);
    }

    public function withTermPolicy(TermPolicy $termPolicy): self
    {
        return new self($this->domain, $this->formality, $termPolicy, $this->glossary, $this->doNotTranslate, $this->instructions);
    }

    public function withGlossary(Glossary $glossary): self
    {
        return new self($this->domain, $this->formality, $this->termPolicy, $glossary, $this->doNotTranslate, $this->instructions);
    }

    /**
     * Replaces the list of terms kept exactly as written, such as product names.
     *
     * @throws InvalidArgumentException when a term is blank, not valid UTF-8 or too long, terms repeat
     *                                  case-insensitively, or there are more than MAX_DO_NOT_TRANSLATE_TERMS
     */
    public function withDoNotTranslate(string ...$terms): self
    {
        return new self($this->domain, $this->formality, $this->termPolicy, $this->glossary, array_values($terms), $this->instructions);
    }

    /**
     * @param string|null $instructions free-form instructions from the developer; null removes them
     *
     * @throws InvalidArgumentException when the instructions are blank, not valid UTF-8 or longer than MAX_INSTRUCTIONS_LENGTH characters
     */
    public function withInstructions(?string $instructions): self
    {
        return new self($this->domain, $this->formality, $this->termPolicy, $this->glossary, $this->doNotTranslate, $instructions);
    }

    /**
     * @param list<string> $terms
     *
     * @return list<string>
     */
    private function doNotTranslateTerms(array $terms): array
    {
        if (\count($terms) > self::MAX_DO_NOT_TRANSLATE_TERMS) {
            throw new InvalidArgumentException(\sprintf('A profile can have at most %d do-not-translate terms, got %d.', self::MAX_DO_NOT_TRANSLATE_TERMS, \count($terms)));
        }

        $normalized = [];
        $seen = [];
        foreach ($terms as $term) {
            $term = ProfileText::normalize($term, 'Do-not-translate term', GlossaryEntry::MAX_TERM_LENGTH, singleLine: true);
            $key = mb_strtolower($term);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException(\sprintf('The do-not-translate term "%s" appears more than once (case-insensitive).', $term));
            }

            $seen[$key] = true;
            $normalized[] = $term;
        }

        return $normalized;
    }

}
