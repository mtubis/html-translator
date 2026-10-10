---
name: work-on-issue
description: Use when asked to work on, implement, start or continue a GitHub issue in this repository (e.g. "work on #4", "implement issue 7"). Runs the plan → tests → implement → verify → review → commit loop.
---

# Work on an issue

Argument: issue number.

1. **Load context**
   - `gh issue view <n>` — read title, scope, acceptance criteria, out-of-scope.
   - Read `docs/ARCHITECTURE.md` sections the issue references and every ADR it names.
   - Read the existing code you will touch and its tests.
2. **Branch**: `git switch -c <type>/<n>-<short-slug>` (e.g. `feat/4-segmenter`) unless already on such a branch.
3. **Plan** (stay in plan mode): list files to create/change, public signatures, and the test cases mapped to each acceptance criterion. Call out any ambiguity or conflict with an ADR as a question. Wait for approval.
4. **Tests first**: write the tests. If the issue touches `src/Html` or `src/Validation`, or says "review tests first", stop and show the test list for review before implementing.
5. **Implement** the minimum to make tests pass, following `CLAUDE.md` rules. Run `composer test` often.
6. **Verify**: `composer check` must be green. Tick each acceptance criterion explicitly in your summary; if one cannot be met, say why.
7. **Review**: ask the `architecture-reviewer` subagent to review `git diff main...HEAD`. For provider/chat-model work also ask `contract-reviewer`. Fix real findings; list the ones you consciously skip with a reason.
8. **Commit** in logical Conventional Commits, each ending with `Refs #<n>`.
9. **Report**: what changed, acceptance criteria status, follow-ups worth new issues (draft their titles). Do not push or open a PR unless asked.
