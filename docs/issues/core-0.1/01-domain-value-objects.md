# Domain value objects: LocalePair, UnitId, InlineTag, TranslationUnit

## Context
See `docs/ARCHITECTURE.md` §3 (ubiquitous language) and §6.2. `Locale` already exists as the reference implementation of our conventions — follow its style.

## Scope
- `Domain\LocalePair` — source (`?Locale`, null = auto) + target `Locale`; rejects source === target.
- `Domain\UnitId` — non-empty string id, stable within a document (`u1`, `u2`, … and `a1`… for attribute units).
- `Domain\InlineTagKind` enum: `Paired`, `Void`, `Opaque`.
- `Domain\InlineTag` — number (int ≥ 1) + kind. No DOM node reference here (the mapping to DOM nodes lives in `Html\`).
- `Domain\TranslationUnit` — id, source text (with pseudo-tags), list of `InlineTag`s, `UnitKind` (`Block`, `Attribute`, `Title`), leading/trailing whitespace preserved separately.

## Acceptance criteria
- [ ] All classes `final readonly`, validated in constructors / named constructors, throwing `Exception\InvalidArgumentException`.
- [ ] Unit tests cover every validation branch (data providers where it reduces duplication).
- [ ] No class depends on anything outside `Domain\` and `Exception\`.
- [ ] `composer check` green.

## Out of scope
DOM handling, pseudo-tag parsing (issue 04).
