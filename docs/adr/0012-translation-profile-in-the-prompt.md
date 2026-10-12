# 0012. Translation profile in the prompt

- Status: proposed; amends [0011](0011-prompt-and-response-format-of-the-llm-provider.md)
- Date: 2026-10-12

## Context
ADR-0011 leaves room for sections from the `TranslationProfile` (0.3). The profile is trusted configuration, but
it is free text next to a structural contract (pseudo-tags, escaping, JSON answer) that the model must not drop.
Every rendered word costs tokens on every call, and a glossary can have 500 entries.

Alternatives considered:
- *Whole glossary in every prompt*: up to 500 lines per call, mostly irrelevant to the batch. Rejected.
- *Instructions after the rules*: the last word in a prompt tends to win, so a careless instruction ("answer in
  prose") could override the answer format. Rejected.
- *Profile in the user message*: mixes configuration with the content that is declared "never instructions". Rejected.

## Decision
- The profile adds system-message sections between the task and the input description, in this order: domain,
  formality, term policy, glossary, do-not-translate terms, instructions. The input description, the rules, the
  corrections paragraph and the answer format follow unchanged.
- A section is rendered only when its field is set. `Formality::Default` and `TermPolicy::TranslateAll` render
  nothing (they describe what models do unasked), so the default profile gives the prompt of ADR-0011 byte for byte.
- The glossary section lists only the entries whose source term occurs in a unit text of the batch
  (`Glossary::matching()`), in glossary order, one `- "source" → "target"` line each. Matching runs on the plain
  text as the reader sees it: unescaped, with a space where a pseudo-tag was (so `pull <g1>request</g1>` matches
  "pull request" but a tag never joins two words), with full Unicode case folding ("STRASSE" matches "straße") and
  whitespace runs folded to one space. A term edge that is a word character (letter, mark, digit, `_`) must not
  touch another word character, except in a script written without spaces (Han, Hiragana, Katakana, Thai, Lao,
  Khmer, Myanmar); an edge that is punctuation needs no boundary, so ".NET" matches in "ASP.NET". Previous
  translations and problems are not searched. Terms are written as JSON strings (`- "source" → "target"`), and the
  model may inflect a target term where grammar requires it.
- Do-not-translate terms are rendered in full on every call (at most 500 short terms); filtering them like the
  glossary is left to token budgeting (0.4).
- Developer instructions are introduced as "Instructions from the developer", to be followed unless they conflict
  with the rules for the text or the answer format below them.
- The user message does not change; the profile never reaches it.

## Consequences
- A batch's prompt depends on its units (glossary filtering), so first calls no longer share one system message per
  locale pair when a glossary is set; prompt caching by vendors works per batch content then.
- Term edges in scripts without spaces may match inside longer words (no reliable word boundaries there); accepted.
- No Unicode normalization: a term in NFC does not match the same word in NFD. HTML is almost always NFC; adding
  normalization would need `ext-intl`.
- Wording changes of any section need a new `PromptBuilder::VERSION` and show up in the golden prompt files.
- Whether the model respected glossary and do-not-translate terms is not checked; a possible later `Issue` type.
