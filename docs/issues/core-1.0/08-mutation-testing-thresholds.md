# Lock in the mutation score

## Context
ROADMAP 1.0 asks for Infection MSI ≥ 85%. On 2026-10-13 the score was already higher: 2,366 mutants, 2,277 killed, 67 escaped, covered MSI 97%. `infection.json5` still requires only `minMsi: 80` / `minCoveredMsi: 85`, so the score could drop by 12 points unnoticed.

## Scope
- Review the 67 escaped mutants (the biggest groups are in `HtmlTranslator`, `ResponseParser`, `Segmenter`, `Reassembler` and `HtmlLoader`):
  - kill the ones that point at missing tests;
  - document the equivalent mutants, or rewrite the code so the mutation no longer applies.
- Raise `minMsi` and `minCoveredMsi` to the reached level, rounded down, e.g. 95 / 96.
- CI already runs Infection (`.github/workflows/ci.yml`; on pull requests only on changed lines). Check that the full run on `main` uses the raised thresholds.

## Acceptance criteria
- [ ] Every escaped mutant is either killed or listed as equivalent, with a reason.
- [ ] The thresholds are raised, and `composer infection` passes with them.
- [ ] `composer check` green.

## Out of scope
Mutating the test-infrastructure classes excluded in `infection.json5`.
