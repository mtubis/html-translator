# Translation profiles

A `TranslationProfile` steers *how* text is translated: domain, formality, term policy, glossary, do-not-translate
terms and free-form instructions (ARCHITECTURE §3). It is an immutable value object in `HtmlTranslator\Profile`;
every `with…()` method returns a new profile.

```php
use HtmlTranslator\Profile\Glossary;
use HtmlTranslator\Profile\GlossaryEntry;
use HtmlTranslator\Profile\TermPolicy;
use HtmlTranslator\Profile\TranslationProfile;

$profile = TranslationProfile::technical()
    ->withTermPolicy(TermPolicy::PreferOriginalTechnicalTerms)
    ->withGlossary(Glossary::fromArray(['pull request' => 'pull request', 'branch' => 'gałąź']))
    ->withDoNotTranslate('GitHub', 'Symfony')
    ->withInstructions('Address the reader directly.');
```

Pass it with the translation options; it applies to every call of the document, corrective retries and the
text-node fallback included (`$translator` is an `HtmlTranslator`, see the [usage guide](usage.md)):

```php
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\TranslationOptions;

$result = $translator->translate($html, Locale::fromString('pl'), new TranslationOptions(profile: $profile));
```

Providers that cannot be steered (`PseudoLocalizationProvider`, non-LLM engines) ignore it.

## Fields

| Field | Type | Default | Meaning |
|---|---|---|---|
| `domain` | `?string` | `null` | Subject area, e.g. "medical devices". |
| `formality` | `Formality` | `Default` | Register of the translation. |
| `termPolicy` | `TermPolicy` | `TranslateAll` | What happens to technical terms and names. |
| `glossary` | `Glossary` | empty | Source term → required target term. |
| `doNotTranslate` | `list<string>` | `[]` | Terms kept exactly as written, e.g. product names. |
| `instructions` | `?string` | `null` | Free-form instructions from the developer (trusted configuration, unlike page content). |

`TranslationProfile::default()` sets nothing: the model decides. `Formality::Default` and `TermPolicy::TranslateAll`
add no instruction to the prompt, since they describe what models do unasked. `withDomain(null)` and
`withInstructions(null)` remove a value; an empty or whitespace-only string is rejected. Text is trimmed, including
Unicode whitespace such as non-breaking spaces.

A term may be in the glossary or in the do-not-translate list, not in both (case-insensitive): the two would give the
model contradicting rules.

### Formality

| Case | Value | Meaning |
|---|---|---|
| `Default` | `default` | No instruction; the model follows the register of the source. |
| `Formal` | `formal` | Formal register ("Sie", "Pan/Pani", "vous"). |
| `Informal` | `informal` | Informal register ("du", "ty", "tu"). |

### TermPolicy

| Case | Value | Meaning |
|---|---|---|
| `TranslateAll` | `translate_all` | Translate technical terms, using established target-language equivalents. |
| `PreferOriginalTechnicalTerms` | `prefer_original_technical_terms` | Keep the source-language technical terms that professionals in the target language use untranslated ("pull request", "framework"); translate the rest. |
| `KeepOriginal` | `keep_original` | Keep every technical term, product name and brand name in the source language. |

The term policy covers categories the model has to recognise; list explicit terms in the glossary or the
do-not-translate list. The string values allow `Formality::from()` / `TermPolicy::from()` in configuration.

### Glossary

```php
$glossary = Glossary::fromArray(['commit' => 'commit', 'branch' => 'gałąź']);
$glossary = $glossary->with(GlossaryEntry::of('merge', 'scalenie'));
$glossary = Glossary::of(GlossaryEntry::of('repo', 'repozytorium'), GlossaryEntry::of('repository', 'repozytorium'));

$glossary->entries();   // list<GlossaryEntry>, in insertion order
$glossary->isEmpty();   // Glossary::empty() is the empty glossary
```

A glossary belongs to one target language: build one profile per target locale. Several source terms may share a
target term. `fromArray()` checks its argument at runtime, see below.

## Building a profile from configuration

A profile can be built from YAML or a PHP array, as the Symfony bundle and the Laravel package do. The entry points
that take arrays check every element at runtime and throw `InvalidArgumentException` for a wrong one, never a
`TypeError`. The message names the parameter, the key and the type of the value, never the value itself:

```php
use HtmlTranslator\Profile\Glossary;
use HtmlTranslator\Profile\TranslationProfile;

$config = ['glossary' => ['pull request' => 'pull request', '2024' => 'MMXXIV'], 'do_not_translate' => ['GitHub']];

$profile = TranslationProfile::technical()
    ->withGlossary(Glossary::fromArray($config['glossary']))     // numeric source terms such as "2024" are fine
    ->withDoNotTranslateTerms($config['do_not_translate']);      // a list; use withDoNotTranslate(...) in typed code
```

- `Glossary::fromArray()` takes a map of source term → target term. A list (`['pull request', 'branch']`, a common
  YAML slip) is rejected: `terms must map source terms to target terms, got a list.` PHP cannot tell such a list from
  a map whose source terms are exactly "0", "1", … in order; build that rare glossary with
  `Glossary::of(GlossaryEntry::of('0', 'zero'), …)`.
- `withDoNotTranslateTerms()` takes a list of strings. Spreading an untyped array into the variadic
  `withDoNotTranslate()` would fail with a `TypeError` under `strict_types`.
- A non-string element is rejected with e.g. `terms["pull request"] must be a string, got int.`

## How the profile reaches the model

`LlmTranslationProvider` adds one system-message section per field that is set, between the task and the input
description
([ADR-0012](adr/0012-translation-profile-in-the-prompt.md)), in this order: domain, formality, term policy,
glossary, do-not-translate terms, instructions. `Formality::Default`, `TermPolicy::TranslateAll` and empty fields
add nothing, so the default profile leaves the prompt as it is. Instructions are introduced as "Instructions from the
developer" and cannot override the rules for pseudo-tags or the answer format, which come after them.

The glossary and do-not-translate sections list only the terms that occur in the units of the batch. A term matches
regardless of case, whitespace and inline markup (`pull <b>request</b>` matches "pull request"), as a whole word ("art"
does not match "party", ".NET" matches in "ASP.NET"); in Chinese, Japanese, Thai and other scripts without spaces it
matches anywhere.

Token cost per call, roughly: 20–40 tokens for each of domain, formality and term policy, 10 tokens per matched
glossary entry or do-not-translate term, plus the instructions. The rendered prompts are in
`tests/Unit/Provider/Llm/Fixtures/prompts/profile-*.txt`.

## Checking the result

After translation, every translated unit is checked against the profile ([ADR-0017](adr/0017-term-checks-after-translation.md)):
a do-not-translate term in the source must appear in the translation (with the profile's letter case when the source
has it), and so must a glossary entry whose target equals its source (`'pull request' => 'pull request'`). A unit that
breaks one gets an `IssueType::TermViolation` issue in `$result->issues()`; the translation is used anyway, without
retries or extra calls. Glossary entries that translate their term are not checked, because the model may inflect the
target ("gałąź" → "gałęzi").

## Presets

| Preset | Domain | Formality | Term policy | Instructions |
|---|---|---|---|---|
| `technical()` | software and technical documentation | `Default` | `PreferOriginalTechnicalTerms` | — |
| `marketing()` | marketing copy | `Default` | `TranslateAll` | Adapt idioms and tone for the target audience instead of translating literally. Keep the length of the text close to the source. |
| `legal()` | legal texts | `Formal` | `TranslateAll` | Translate precisely. Do not paraphrase or summarise. Translate defined terms consistently. |

Presets are starting points; change any field with the `with…()` methods.

## Limits

Limits keep prompts bounded. Breaking one throws `HtmlTranslator\Exception\InvalidArgumentException`, as does a wrong
element in an array argument (see [Building a profile from configuration](#building-a-profile-from-configuration)).

| What | Limit |
|---|---|
| Glossary or do-not-translate term | 200 characters |
| Glossary entries | 500, source terms unique (case-insensitive) |
| Do-not-translate terms | 500, unique (case-insensitive) |
| Domain | 200 characters |
| Instructions | 2,000 characters |

All text must be valid UTF-8. Terms and the domain are single-line: line breaks and control characters are rejected.
Instructions may span several lines.
