# 0007. Inline tag to DOM node mapping lives in the Html layer

- Status: accepted
- Date: 2026-10-10
- Supersedes: the mapping clause of [0003](0003-inline-markup-as-numbered-pseudo-tags.md) ("The unit keeps the mapping N → original DOM node"). The rest of 0003 stands.

## Context
ADR-0003 says a translation unit keeps the mapping from pseudo-tag number to the original DOM node. `TranslationUnit` is a `Domain` value object, and the dependency rule (ARCHITECTURE §7) forbids `Domain` from depending on anything else, `Dom\` included. Holding `Dom\Node` references would also make units unsuitable as cache keys and provider input, and impossible to compare by value.

Alternatives considered:
- *Generic `object` reference in `InlineTag`*: keeps the dependency out of the signature only; still mutable state inside a readonly value object, still not comparable. Rejected.
- *A `Domain` interface for "node handle" implemented in `Html`*: an abstraction with exactly one implementation and no consumer in `Domain`. Rejected.

## Decision
`Domain\TranslationUnit` holds only `Domain\InlineTag`s: the pseudo-tag number and its kind (`Paired`, `Void`, `Opaque`). It holds no DOM reference.

The mapping from unit id and pseudo-tag number to the original DOM nodes is owned by the `Html` layer. `Html\Segmenter` builds it and returns it together with the units (as `Html\SegmentedDocument`). `Html\Reassembler` consumes it. Nothing outside `Html` sees DOM nodes.

## Consequences
- `Domain` stays free of DOM; units can be cached, serialized, compared and passed to providers as plain values.
- `Html\SegmentedDocument` must keep units and their mapping consistent. The Reassembler must treat a unit id or tag number missing from the mapping as a programming error, not as model output to tolerate.
- Validation (`StructureValidator`) works only from `InlineTag`s and never needs the DOM.
