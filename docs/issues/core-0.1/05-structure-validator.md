# StructureValidator

## Context
ADR-0003, ARCHITECTURE §6.5. Its error messages are later sent back to the model in corrective retries, so they must be precise and human-readable.

## Scope
- `Validation\StructureValidator::validate(TranslationUnit $source, string $translated): ValidationResult`
- Checks: same multiset of pseudo-tags; paired tags properly nested and closed; no unknown tag numbers; no raw HTML tags or entities that would become markup; attribute/title units contain no tags; non-empty result when source was non-empty.
- `Validation\ValidationResult` — `isValid()`, `violations(): list<Violation>`, `describe(): string`.

## Acceptance criteria
- [ ] One test per violation type plus combinations; messages asserted exactly.
- [ ] Reordered but valid tags pass (`<g1>a</g1> <g2>b</g2>` ↔ `<g2>b</g2> <g1>a</g1>`).
- [ ] `composer check` green; Infection MSI for `src/Validation` ≥ 90 %.
