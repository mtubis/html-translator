# Reassembler

## Context
ADR-0003, ARCHITECTURE §6.6. Security-relevant: model output must never become markup.

## Scope
- `Html\Reassembler::apply(SegmentedDocument, array<string, string> $translations, Locale $target): void` — replaces unit content in the DOM; units missing from the map stay untouched.
- Pseudo-tags → clones of original inline elements (with attributes and, for opaque tags, original subtree).
- Text → DOM text nodes only.
- Sets `<html lang>` to the target; sets `dir="rtl"` for RTL targets (ar, he, fa, ur …) — keep the RTL list in `Domain\Locale`.

## Acceptance criteria
- [ ] Round-trip property: `reassemble(segment(x), identity translations)` ≡ `x` for every fixture.
- [ ] A translation containing `<script>alert(1)</script>` ends up as escaped text, not an element (explicit test).
- [ ] Reordered pseudo-tags produce reordered elements.
- [ ] `composer check` green.
