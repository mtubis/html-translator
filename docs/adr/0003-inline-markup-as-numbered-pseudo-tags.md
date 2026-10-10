# 0003. Inline markup sent as numbered pseudo-tags

- Status: accepted; mapping clause superseded by [0007](0007-inline-tag-mapping-lives-in-html-layer.md)
- Date: 2026-10-10

## Context
Translating text node by text node destroys context ("Click <b>here</b> to continue" → three unrelated fragments) and produces poor translations. Sending raw HTML lets the model rewrite attributes, invent tags or break nesting, and makes model output an injection vector.

## Decision
Within a translation unit, every inline element is replaced by a numbered pseudo-tag without attributes: paired `<gN>…</gN>`, void `<xN/>`. Inline elements matched by skip rules (e.g. inline `<code>`) become opaque `<xN/>` and their content is not sent. The unit keeps the mapping N → original DOM node (superseded by ADR-0007: the unit keeps only tag numbers and kinds; the Html layer owns the mapping to DOM nodes). The model may move pseudo-tags to fit target-language word order but must keep the same multiset of tags with valid nesting. On reassembly, pseudo-tags are replaced with clones of the original elements; translated text is inserted as text nodes, never parsed as HTML.

## Consequences
- Word order can change while markup stays attached to the right words.
- Attributes (URLs, classes, data-*) are never exposed to the model and cannot be altered.
- Validation is a cheap, exact comparison of tag sets and nesting.
- Duplicated or dropped pseudo-tags are detectable and trigger corrective retries / fallback.
