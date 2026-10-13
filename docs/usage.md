# Usage guide

How to translate HTML with `html-translator`, from the first call to errors and costs. Setting up a real model is
covered in the [README](../README.md) and in [providers](providers.md). The examples here use
`PseudoLocalizationProvider`, which needs no network ("Hello" becomes "[!! Ĥéļļö !!]"), so you can run them as they
are and see exactly what happens to the markup; with a real model only the provider changes.

```php
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\HtmlTranslator;
use HtmlTranslator\Provider\Testing\PseudoLocalizationProvider;

$translator = new HtmlTranslator(new PseudoLocalizationProvider());
$polish = Locale::fromString('pl');
```

## Documents and fragments

`translate()` takes a full document or a fragment and returns the same kind:

- **A document** starts with `<!DOCTYPE`, `<html>`, `<head>` or `<body>` (after whitespace and comments). Its `<title>` and `<meta>`
  descriptions are translated, `<html lang>` is set to the target and `dir="rtl"` is added for right-to-left targets.
- **A fragment** is anything else, e.g. the body of a CMS field. It is parsed in a `<body>` context, or in a table when
  it starts with a table part such as `<tr>` or `<td>`, and only the fragment is returned.

```php
$document = '<!DOCTYPE html><html lang="en"><head><title>Pricing</title>'
    . '<meta name="description" content="Plans for teams"></head>'
    . '<body><img src="team.png" alt="Our team"><p>Choose a plan.</p></body></html>';

echo $translator->translate($document, Locale::fromString('ar'))->html();
```

```text
<!DOCTYPE html><html lang="ar" dir="rtl"><head><title>[!! Þŕíçíñĝ !!]</title><meta name="description" content="[!! Þļáñš ƒöŕ ţéáɱš !!]"></head><body><img src="team.png" alt="[!! Öüŕ ţéáɱ !!]"><p>[!! Çĥööšé á þļáñ. !!]</p></body></html>
```

```php
echo $translator->translate('<tr><td>Monthly</td><td>Yearly</td></tr>', $polish)->html();
```

```text
<tr><td>[!! Ṁöñţĥļý !!]</td><td>[!! Ýéáŕļý !!]</td></tr>
```

What is sent to the model, and what is not:

- **Text** is sent in units: one unit per run of text in a block element (a paragraph, a list item, a table cell),
  with inline elements (`<a>`, `<strong>`, `<span>`, …) as numbered placeholders. The model never sees the elements'
  attributes; they are restored from the original after validation.
- **Attributes**: `alt`, `title`, `placeholder`, `aria-label` and `aria-description`, the `value` of `<input>` buttons (not of submit buttons with a `name`, whose value is form data),
  and `<meta>` `description`, `keywords`, `og:title` and `og:description`. Everything else (`href`, `class`, `src`, …)
  is kept as it is.
- **Never**: `<code>`, `<pre>`, `<kbd>`, `<samp>`, `<var>`, `<svg>`, `<math>`, `<textarea>`, scripts, styles,
  templates, and anything marked `translate="no"` or `class="notranslate"`.

## Choosing what is translated

`translate="no"` and the `notranslate` class work as in browsers: they apply to the element and everything inside it,
and `translate="yes"` inside switches translation back on.

```php
echo $translator->translate(
    '<p>Install <span translate="no">html-translator</span> with <code>composer require</code>.</p>',
    $polish,
)->html();
```

```text
<p>[!! Íñšţáļļ <span translate="no">html-translator</span> ŵíţĥ <code>composer require</code>. !!]</p>
```

To skip your own elements or classes, or to translate more attributes, pass `SkipRules` and
`TranslatableAttributes` to the translator:

```php
use HtmlTranslator\Html\SkipRules;
use HtmlTranslator\Html\TranslatableAttributes;

$custom = new HtmlTranslator(
    new PseudoLocalizationProvider(),
    skipRules: SkipRules::defaults()->withClass('brand')->withElement('address'),
    translatableAttributes: TranslatableAttributes::defaults()->withAttribute('data-tooltip'),
);

echo $custom->translate(
    '<p class="brand">Acme Cloud</p><p data-tooltip="Opens a new tab">Read the docs</p>',
    $polish,
)->html();
```

```text
<p class="brand">Acme Cloud</p><p data-tooltip="[!! Öþéñš á ñéŵ ţáƀ !!]">[!! Ŕéáð ţĥé ðöçš !!]</p>
```

## When a unit cannot be translated

A model may break the placeholders, refuse a unit or leave it out. The translator then asks again with the problem
described (corrective retries, `maxCorrectiveRetries`, 2 by default). A unit that still has no valid translation is
handled by the `FallbackStrategy` and reported as an `Issue`; the result is still valid HTML.

| Strategy | The unit… |
|---|---|
| `KeepOriginal` (default) | keeps its source text. |
| `TextNodesOnly` | is translated text node by text node, without its inline markup as context; text nodes that still fail keep their source text. |
| `Throw` | aborts the translation with a `StructureValidationException` or `ProviderException`. |

Here every answer breaks the markup, even on retry:

```php
use HtmlTranslator\FallbackStrategy;
use HtmlTranslator\TranslationOptions;

$unreliable = new HtmlTranslator(PseudoLocalizationProvider::corruptingEvery(1, persistent: true));
$html = '<p>Read the <a href="/guide">guide</a>.</p>';

$kept = $unreliable->translate($html, $polish);
echo $kept->html(), "\n";
var_dump($kept->isComplete());
foreach ($kept->issues() as $issue) {
    echo $issue->type->name, ': ', $issue->message, "\n";
}

echo $unreliable->translate($html, $polish, new TranslationOptions(fallback: FallbackStrategy::TextNodesOnly))->html();
```

```text
<p>Read the <a href="/guide">guide</a>.</p>
bool(false)
InvalidStructure: The translation of unit "u1" is still invalid after 2 corrective retries:
- Close <g1> with </g1>.
<p>[!! Ŕéáð ţĥé !!] <a href="/guide">[!! ĝüíðé !!]</a>[!! . !!]</p>
```

`IssueType` tells you what happened: `InvalidStructure`, `ProviderFailure` (the model refused the unit),
`MissingTranslation`, `PartiallyTranslated` (the `TextNodesOnly` fallback left some text nodes in the source language)
and `TermViolation` (the translation changed a term of the profile; it is used anyway).

## Steering the translation

A [translation profile](profiles.md) sets the domain, formality, term policy, a glossary, terms that must stay as
they are and your own instructions:

```php
use HtmlTranslator\Profile\Formality;
use HtmlTranslator\Profile\Glossary;
use HtmlTranslator\Profile\TranslationProfile;

$options = new TranslationOptions(
    source: Locale::fromString('en'),
    profile: TranslationProfile::technical()
        ->withFormality(Formality::Informal)
        ->withGlossary(Glossary::fromArray(['pull request' => 'pull request']))
        ->withDoNotTranslate('GitHub'),
);

$result = $translator->translate('<p>Open a pull request on GitHub.</p>', $polish, $options);
```

Language models get the profile in the prompt; `PseudoLocalizationProvider` ignores it. After translation, a unit
whose translation changed "GitHub" gets a `TermViolation` issue. Every unit also carries
context hints: the page title and the nearest heading before it, which helps with short, ambiguous strings such as
button labels ([ADR-0013](adr/0013-context-hints-in-the-prompt.md)). They are added automatically.

## Batching, caching and costs

Units are sent in batches: a batch closes at `tokenBudget` (about 2,000 tokens of unit text by default) or at
`batchSize` units (20). Larger batches mean fewer calls, so the instructions in each prompt are paid for less often.
`onProgress` is called after every batch:

```php
use HtmlTranslator\Progress;

$page = '<h1>Release notes</h1><p>Faster batching.</p><p>A unit cache.</p><p>Token counts.</p>';
$options = new TranslationOptions(
    batchSize: 2,
    onProgress: static function (Progress $progress): void {
        printf("batch %d/%d, units %d/%d\n", $progress->batchesDone, $progress->batchesTotal, $progress->unitsDone, $progress->unitsTotal);
    },
);

$stats = $translator->translate($page, $polish, $options)->stats();
printf("units: %d, batches: %d, retries: %d\n", $stats->units, $stats->batches, $stats->retries);
```

```text
batch 1/2, units 2/4
batch 2/2, units 4/4
units: 4, batches: 2, retries: 0
```

With a PSR-6 cache pool, translated units are stored and reused: the next translation of the same text, with the
same source and target locale (a `null` source is a locale of its own), profile and model, costs nothing, in any
document. Changing the model (for Anthropic also its effort, betas and refusal fallbacks), the prompt version (a
library update) or the profile starts a fresh cache
([ADR-0016](adr/0016-unit-cache-and-cacheable-providers.md)). Set an expiry on the pool if you need one.

```php
$cached = new HtmlTranslator(new PseudoLocalizationProvider(), cache: $cache);

$cached->translate($page, $polish);
$stats = $cached->translate($page, $polish)->stats();
printf("from the cache: %d of %d units, batches: %d\n", $stats->cacheHits, $stats->units, $stats->batches);
```

```text
from the cache: 4 of 4 units, batches: 0
```

With a language model, `stats()->inputTokens` and `outputTokens` add up the tokens reported by the API for every
call of the document, retries included (`null` when the API reports none): multiply them by your model's prices to
get the cost of a page. It is a lower bound: a call that ends in an error adds no tokens.

## Errors

`translate()` does not throw for problems with single units: they become issues, see above. It throws only when the
whole translation cannot go on. Every exception implements `HtmlTranslator\Exception\TranslatorException`.

| Exception | When |
|---|---|
| `InvalidHtmlException` | The input is empty or whitespace only, or its elements are nested deeper than 1024 levels. |
| `InvalidArgumentException` | Invalid options or settings, e.g. the same source and target locale. |
| `ProviderException` | The provider cannot be used at all: network errors after transport retries, authentication, rate limits that do not end. `statusCode` and `vendorCode` say more. Also with `FallbackStrategy::Throw` for a refused or missing unit. |
| `StructureValidationException` | Only with `FallbackStrategy::Throw`: a unit still breaks the markup after corrective retries. |

```php
use HtmlTranslator\Exception\TranslatorException;

try {
    $translator->translate('<p>Hello</p>', $polish, new TranslationOptions(source: Locale::fromString('pl')));
} catch (TranslatorException $exception) {
    echo $exception::class, ': ', $exception->getMessage(), "\n";
}
```

```text
HtmlTranslator\Exception\InvalidArgumentException: Source and target locale are both "pl".
```

Messages never contain API keys, and text they quote from the input, the model or the API is shortened and kept on
one line, so they are safe to log.
