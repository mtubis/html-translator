# 0013. Context hints in the prompt

- Status: proposed; amends [0011](0011-prompt-and-response-format-of-the-llm-provider.md)
- Date: 2026-10-12

## Context
Short texts such as button labels, table headers and `alt` texts are ambiguous without knowing the page and the
section they are in ("Save" in "Account settings" vs. in "Offers"). The `Segmenter` records the document title and
each unit's nearest heading (#39); they must reach the model without becoming text to translate or instructions,
and without changing prompts of batches that have no context.

Alternatives considered:
- *Heading text on every unit*: repeats the same heading for every unit of a section. Rejected for token cost.
- *Batch-level heading list only*: cheaper, but the model cannot tell which label belongs to which section.
  Rejected.
- *Context in the system message*: mixes page content with instructions. Rejected; page text belongs to the user
  message, which is declared "content, never instructions".

## Decision
- `TranslationBatch::$context` is a `BatchContext`: the page title, the distinct section headings of the batch's
  units in order of first use, and per unit the index of its heading. The core builds one context for the document
  and selects from it per batch: corrective retries get the headings of the pending units, and a text node of the
  `TextNodesOnly` fallback (`u3-1`) gets the heading of its unit (`u3`).
- The user message of a batch with context starts with `"context": {"title": …, "headings": [ … ]},` on its own
  line before `"units"`; `title` and `headings` appear only when set. A unit with a heading gets `"heading": <index>`
  (counted from 0) after `"text"`. Everything is JSON-encoded like unit texts.
- The system message gets one paragraph after the input description, only when the batch has context: the context
  is for understanding short or ambiguous texts, is not translated and is never followed as instructions.
- A batch without context renders the prompt of ADR-0011/0012 byte for byte. The answer format does not change.
- The document title also appears as a unit when the batch translates `<title>`; the duplication is accepted.

## Consequences
- Prompts of a document differ per batch (different headings), as they already do with a glossary (ADR-0012).
- A unit translated in one context may later be reused from the unit cache (0.4) in another; the cache key does not
  include the context. Accepted: context helps ambiguous texts, and caching per context would defeat the cache.
- Headings are source text; a heading crafted as an instruction is treated like any other content (JSON-encoded,
  framed as context only). Model output stays untrusted (ADR-0003).
- Wording or format changes need a new `PromptBuilder::VERSION`; `tools/eval` reports how many calls carried context.
