# 0017. Term checks after translation

- Status: proposed
- Date: 2026-10-13

## Context
Since ADR-0012 the prompt carries the do-not-translate terms and glossary entries of a batch, but nothing checks
whether the model followed them: a translated product name goes unnoticed (#47). A check must not cost provider calls,
must not block a document, and must avoid false alarms, because the model may inflect glossary targets (ADR-0012), and
inflected languages change word endings ("gałąź" → "gałęzi").

Alternatives considered:
- *Check every glossary entry by exact match*: flags correct inflected translations in Polish, Czech, Finnish, …
  Rejected.
- *Opt-in option for checking all glossary entries*: new public API whose result is mostly noise in inflected
  languages. Deferred until `tools/eval` shows false-positive rates.
- *Corrective retries for violations*: more calls and possible loops on a stubborn model. Out of scope (#47).

## Decision
- `Validation\TermChecker` checks every translation that ends up in the document (from the provider, the unit cache
  or the `TextNodesOnly` fallback; units that keep their original text are not checked), on the plain text a reader
  sees (`UnitText::plainText()`: escapes decoded, a space for every pseudo-tag) with the matching rules of the prompt
  (`Profile\TermMatcher`: whitespace folding, whole words; a tag between the words of a term does not break it).
- A do-not-translate term that occurs in the source must occur in the translation. When the source has it in the
  profile's letter case, the translation must too; when the source has it only in another case (a heading in
  capitals), any case is accepted.
- A glossary entry is checked only when its target equals its source after `mb_strtolower()` ("pull request" →
  "pull request"); then the target must occur in the translation, in any case. Entries that translate their term are
  not checked.
- Each unit that breaks any term gets one `Issue` of the new type `IssueType::TermViolation`, naming every broken term
  in profile order. The translation is used anyway: `isComplete()` is unaffected, there is no retry, no fallback and
  no extra provider call, also with `FallbackStrategy::Throw`. Term issues follow the other issues of the document.
  A message names at most three terms, then "and N more"; terms are quoted with `UntrustedText`.
- `HtmlTranslator` takes the checker as an optional named collaborator, `termChecker` (ADR-0010).

## Consequences
- Product names and kept terms changed by the model show up in `issues()` and in the log.
- Translated glossary terms are not verified; a model that ignores "branch" → "gałąź" goes unnoticed.
- A term the source contains but the translation legitimately rephrases (e.g. a sentence that drops a brand name)
  is reported; such issues are informational.
- Occurrence counts are not compared: a term kept once but dropped a second time in the same unit is not reported.
