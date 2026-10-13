# tools/eval: failing exit code and cache hits in the report

## Context
`tools/eval` exits with 0 even when fixtures fail, so it cannot gate a CI job. Its report also does not show `TranslationStats::$cacheHits`, which matters when evaluating with a cache.

## Scope
- Exit code 2 when any fixture is incomplete or ends in an error, unless `--allow-failures` is passed. Exit code 1 stays for usage and setup errors (unknown argument, missing `.env` variable, unwritable report), so CI can tell a worse model from a broken run.
- A `Cache hits` column per fixture and a total in the Markdown and JSON reports.
- `tools/eval/README.md` documents the exit codes.

## Acceptance criteria
- [ ] A run with a failing fixture exits with 2, and with 0 when `--allow-failures` is passed; usage errors still exit with 1.
- [ ] Reports show cache hits; existing report tests updated.
- [ ] `composer check` green.

## Out of scope
New metrics beyond cache hits.
