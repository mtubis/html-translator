# Segmenter records the document title and the section heading of every unit

## Context
ARCHITECTURE §6.3: "Each batch carries document context hints: page title, neighbouring headings". Short strings such as button labels, table headers and `alt` texts are ambiguous without context. This issue only collects the context in the `Html` layer. The next issue sends it to the model.

**Touches `src/Html`: show the tests for review before implementing.**

## Scope
- `SegmentedDocument` gains:
  - `title(): ?string`: the text content of `<title>`, with whitespace collapsed; `null` for fragments and documents without a title.
  - `sectionHeadingOf(UnitId): ?string`: the text of the nearest heading (`h1`–`h6`) before the unit in document order, with whitespace collapsed. A heading unit has the previous heading as its context, not itself. Units before the first heading get `null`.
- These are plain strings in the source language; no DOM nodes leave the `Html` layer (ADR-0007).
- Heading text is the element's text content. Pseudo-tags and skipped inline content (`<code>`) are included as plain text, since they are context and not sent for translation.
- A heading inside a skipped subtree (`translate="no"`, `.notranslate`, `<svg>`) still counts as context.
- Length cap per hint (e.g. 200 characters, cut on a character boundary, not mid-UTF-8) so a huge heading cannot blow up a prompt.

## Acceptance criteria
- [ ] Unit tests cover:
  - a document with a title and nested sections (`h1` > `h2` > `h3`);
  - units before the first heading;
  - a heading that contains inline markup;
  - headings in a fragment;
  - a heading inside `translate="no"`;
  - the length cap with multibyte text.
- [ ] Segmentation output (units and ids) is unchanged for every golden fixture; the golden fixture harness proves it.
- [ ] `composer check` green.

## Decided
- Nearest heading only, not the chain of heading levels.
- A heading inside a skipped subtree counts as context.
- `role="heading"` does not count in 0.3.

## Out of scope
- Putting the hints into `TranslationBatch` and the prompt (next issue).
- Neighbouring paragraphs as context.
