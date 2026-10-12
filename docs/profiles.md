# Translation profiles

A `TranslationProfile` steers *how* text is translated: domain, formality, term policy, glossary, do-not-translate
terms and free-form instructions (ARCHITECTURE §3). It is an immutable value object in `HtmlTranslator\Profile`;
every `with…()` method returns a new profile.

> Status (0.3, in progress): the value objects are in place; passing a profile to `HtmlTranslator` through
> `TranslationOptions` and rendering it into the prompt are next.

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
target term. `fromArray()` expects string values; validate untyped configuration first, because a non-string value
raises a `TypeError`.

## Presets

| Preset | Domain | Formality | Term policy | Instructions |
|---|---|---|---|---|
| `technical()` | software and technical documentation | `Default` | `PreferOriginalTechnicalTerms` | — |
| `marketing()` | marketing copy | `Default` | `TranslateAll` | Adapt idioms and tone for the target audience instead of translating literally. Keep the length of the text close to the source. |
| `legal()` | legal texts | `Formal` | `TranslateAll` | Translate precisely. Do not paraphrase or summarise. Translate defined terms consistently. |

Presets are starting points; change any field with the `with…()` methods.

## Limits

Limits keep prompts bounded. Breaking one throws `HtmlTranslator\Exception\InvalidArgumentException`.

| What | Limit |
|---|---|
| Glossary or do-not-translate term | 200 characters |
| Glossary entries | 500, source terms unique (case-insensitive) |
| Do-not-translate terms | 500, unique (case-insensitive) |
| Domain | 200 characters |
| Instructions | 2,000 characters |

All text must be valid UTF-8. Terms and the domain are single-line: line breaks and control characters are rejected.
Instructions may span several lines.
