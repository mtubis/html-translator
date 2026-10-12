# Translation profile in TranslationOptions, TranslationBatch and the prompt

## Context
ARCHITECTURE §4 (`TranslationOptions::$profile`), §6.4, ADR-0010 (new parameters are named and have defaults) and ADR-0011 ("translation profiles add sections"). Builds on the `Profile` value objects from the previous issue.

## Scope
- `TranslationOptions`: add `profile: TranslationProfile = TranslationProfile::default()` as a named parameter (ADR-0010).
- `TranslationBatch`: add `public TranslationProfile $profile` with a default. `HtmlTranslator` passes the profile into every batch, including corrective retries and the `TextNodesOnly` fallback call.
- `PromptBuilder` renders the profile as extra system-message sections, placed after the task and before the input description. Each section appears only when it is set:
  - domain;
  - formality: one sentence per case, nothing for `Default`;
  - term policy: one paragraph per case;
  - do-not-translate terms: a list, kept exactly as written, including case;
  - glossary: only the entries whose source term occurs in a unit of the batch. Matching is case-insensitive, Unicode-aware, on word boundaries and against the unit text without pseudo-tags. Entries keep insertion order;
  - instructions: labelled as the developer's instructions. This is trusted configuration, unlike unit texts.
- `TranslationProfile::default()` renders nothing extra. The system message for a batch without a profile is then byte-for-byte the 0.2 prompt; the existing golden prompts prove it.
- Bump `PromptBuilder::VERSION` and add golden prompts in `tests/Unit/Provider/Llm/Fixtures/prompts/`: each preset, a glossary filtered to the batch, and a profile with every field set.
- New ADR (`write-adr` skill) on how the profile is rendered: section order, which sections are left out when empty, glossary filtering and matching, and how developer instructions are framed. It amends ADR-0011.
- Update `docs/profiles.md` with how the profile reaches the model and what it costs in tokens.

## Acceptance criteria
- [ ] A batch without a profile renders exactly the 0.2 system message; the existing golden prompt files stay unchanged.
- [ ] The profile reaches every provider call: first call, corrective retry and `TextNodesOnly` fallback. Asserted on the requests recorded by `FakeChatModel`.
- [ ] Glossary filtering is covered by tests:
  - matching is case-insensitive;
  - a term split by a pseudo-tag (`pull <g1>request</g1>`) is matched;
  - a substring inside a longer word (`art` in `party`) is not matched;
  - non-Latin scripts are matched.
- [ ] `PseudoLocalizationProvider` ignores the profile, so golden fixtures do not change.
- [ ] `PromptBuilder::VERSION` is bumped and the new golden prompts are reviewed.
- [ ] `composer check` green.

## Decided
- No batch splitting for glossary size in 0.3 (token budgeting is 0.4); the limits from the previous issue are documented.
- Glossary matching strips pseudo-tags first and keeps a word boundary where a tag was, so `pull <g1>request</g1>` matches "pull request". Update the matching test case accordingly.

## Out of scope
- Checking after translation that glossary or do-not-translate terms were respected (possible follow-up: an `Issue` type for term violations).
- Context hints (separate issues).
- Profile fingerprint and cache (0.4).
