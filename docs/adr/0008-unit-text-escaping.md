# 0008. Unit text escapes &, < and >

- Status: accepted
- Date: 2026-10-10

## Context
ADR-0003 puts pseudo-tags (`<gN>`, `</gN>`, `<xN/>`) into plain text. Without escaping, source text that literally contains `<g1>` or `<x2/>` (documentation pages, code samples outside `<code>`) cannot be told apart from a pseudo-tag, and neither the validator nor the reassembler could decode it unambiguously.

Alternatives considered:
- *No escaping, hope for no collisions*: silent corruption on exactly the pages that talk about markup. Rejected.
- *Escape only collision-shaped substrings*: needs its own escape for the escape character; harder to explain to the model than a convention it already knows. Rejected.

## Decision
The text of every translation unit (block, attribute, title) is escaped like XML character data: `&` → `&amp;`, `<` → `&lt;`, `>` → `&gt;`. Pseudo-tags are the only unescaped `<`…`>` in unit text. The Segmenter (`InlineEncoder`) escapes; nothing else in the text is changed.

When decoding model output (validator, reassembler): `&amp;`, `&lt;` and `&gt;` are the only recognised entities. A bare `&` or `>` is taken literally (models often unescape them). A bare `<` that does not start a valid pseudo-tag is a structure violation ("raw markup") and triggers a corrective retry. Decoded text is inserted as text nodes (ADR-0003), so escaping is about unambiguous parsing, not about injection.

## Consequences
- Units that talk about markup round-trip exactly.
- The prompt must state the convention; the model sees `Tom &amp; Jerry` instead of `Tom & Jerry`, which costs a few tokens and occasionally gets unescaped (tolerated as above).
- The format lives in `Domain\UnitText`, because both `Html` (encoder, reassembler) and `Validation` use it and `Validation` must not depend on `Html`. `StructureValidator` (#6) and `Reassembler` (#7) share its decoder.
