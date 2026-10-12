# Translation profile value objects: TranslationProfile, TermPolicy, Formality, Glossary, presets

## Context
ARCHITECTURE §1 (goal 3: steerability), §3 (ubiquitous language), §4 (public API sketch), §7 (`Profile/` depends on nothing else in `src/`). This issue adds only the `Profile` value objects and presets. The next issue wires them into `TranslationOptions`, `TranslationBatch` and the prompt.

## Scope
- `Profile\TranslationProfile`: `final readonly`, immutable, with withers. Fields:
  - `?string $domain`: free text, e.g. "software documentation".
  - `Formality $formality`.
  - `TermPolicy $termPolicy`.
  - `Glossary $glossary`.
  - `list<string> $doNotTranslate`: terms kept exactly as written, e.g. product names.
  - `?string $instructions`: free-form text from the developer.
  - Named constructors: `default()` (nothing set; the model decides), `technical()`, `marketing()`, `legal()`.
  - Withers: `withDomain()`, `withFormality()`, `withTermPolicy()`, `withGlossary()`, `withDoNotTranslate()`, `withInstructions()`. They follow the §4 example: `TranslationProfile::technical()->withTermPolicy(…)->withGlossary(Glossary::fromArray([…]))`.
- `Profile\Formality` enum: `Default` (the model follows the source), `Formal`, `Informal`.
- `Profile\TermPolicy` enum: `TranslateAll`, `PreferOriginalTechnicalTerms`, `KeepOriginal`. Each case gets PHPDoc saying exactly which terms it covers; the prompt wording follows in the next issue.
- `Profile\GlossaryEntry` (`final readonly`: `string $source`, `string $target`) and `Profile\Glossary` (`final readonly`). Glossary API:
  - `fromArray(array<string, string>)` and `of(GlossaryEntry ...)`.
  - `entries(): list<GlossaryEntry>` in insertion order.
  - `isEmpty()` and `with(GlossaryEntry)`.
- Validation in constructors and named constructors, with `Exception\InvalidArgumentException`:
  - Rejected: blank strings, a source term duplicated case-insensitively (Unicode, `mb_strtolower`), duplicate do-not-translate terms, and text over the length limits.
  - Strings are trimmed of surrounding whitespace.
- Preset contents, documented in PHPDoc and `docs/profiles.md`:
  - `technical()`: domain "software and technical documentation", `PreferOriginalTechnicalTerms`, `Formality::Default`.
  - `marketing()`: domain "marketing copy", `Formality::Default`, `TranslateAll`. Instructions: adapt idioms and tone for the target audience instead of translating literally, and keep the text's length close to the source.
  - `legal()`: domain "legal texts", `Formality::Formal`, `TranslateAll`. Instructions: translate precisely, do not paraphrase or summarise, and keep defined terms consistent.

## Acceptance criteria
- [ ] Every invalid state listed above is rejected with a clear message, and a unit test covers each one.
- [ ] Withers return a new instance and leave the original unchanged.
- [ ] Each preset has a table test that pins its documented field values.
- [ ] `Profile` depends on nothing else in `src/` except `Exception` (ARCHITECTURE §7).
- [ ] `docs/profiles.md` describes the fields, the enums and the presets.
- [ ] `composer check` green.

## Decided
- Glossaries are not bound to a locale: one profile per target language, no `Locale` on entries. Framework packages map config per locale.
- `TermPolicy::KeepOriginal` stays: the do-not-translate list is for explicit terms, the policy for categories the model has to recognise.
- Length limits: 200 characters per term, 500 glossary entries, 2,000 characters of instructions.

## Out of scope
- Rendering the profile in the prompt, and the profile in `TranslationOptions` / `TranslationBatch` (next issue).
- Profile fingerprint for the cache key (comes with the PSR-6 cache in 0.4).
- Loading glossaries from files (CSV/TBX); that belongs to the framework packages.
