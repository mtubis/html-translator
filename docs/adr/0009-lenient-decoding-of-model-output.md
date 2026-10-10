# 0009. Lenient decoding of model output

- Status: accepted
- Date: 2026-10-10
- Supersedes: the decoding paragraph of [0008](0008-unit-text-escaping.md) ("When decoding model output …"). Escaping stays as decided in 0008.

## Context
ADR-0008 made decoding strict: only `&amp;`, `&lt;`, `&gt;` are entities and every bare `<` that is not a pseudo-tag is a violation. Designing the tokenizer showed that strictness triggers corrective retries where nothing is at risk, because decoded text is always inserted as text nodes (ADR-0003):
- models routinely write `a < b` instead of `a &lt; b`;
- a model writing `&nbsp;` or `&#169;` would leave the literal string "&nbsp;" on the page;
- models often write `<x1 />` with a space.

## Decision
`Domain\UnitText::tokenize()` decodes model output as follows; the validator and the reassembler both use it.
- Pseudo-tags are `<gN>`, `</gN>` and `<xN/>` with `N` = `[1-9][0-9]*`, lower-case `g`/`x`; optional ASCII whitespace is allowed right before `>` and `/>` (`<x1 />`, `<g1 >`), nowhere else.
- A `<` followed by an ASCII letter, `/`, `!` or `?` that does not form a pseudo-tag is raw markup, i.e. a structure violation (this is exactly where an HTML parser would start a tag or comment). Any other `<` is literal text.
- Text is decoded with all HTML5 character references (`html_entity_decode`, `ENT_QUOTES | ENT_HTML5`), in one pass: `&amp;nbsp;` becomes the literal `&nbsp;`. Unknown references and a bare `&` stay as they are, and so do references `html_entity_decode` refuses: legacy names without a semicolon (`&nbsp`), and the null, surrogate and C1 numeric references.
- Tag numbers have at most six digits; longer numbers are raw markup (no integer overflow, no collapsing of distinct numbers).
- The tokenizer never throws and runs in linear time; every problem becomes a token the validator reports. Invalid UTF-8 is a separate violation, checked by the validator before tokenizing.

## Consequences
- Fewer pointless corrective retries; literal `<` and entities written by the model render as intended.
- Invented tags (`<b>`, `<a href>`, `<!-- -->`) are still caught as raw markup.
- The pseudo-tag grammar lives in one place (`Domain\UnitText`); encoder, validator and reassembler must not re-implement it.
