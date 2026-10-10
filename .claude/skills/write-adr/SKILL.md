---
name: write-adr
description: Use when a design decision is made or changed, when an implementation needs to deviate from an accepted ADR, or when asked to document a decision.
---

# Write an ADR

1. List `docs/adr/` and read ADRs related to the topic. If the new decision changes an accepted one, the new ADR must say "Supersedes NNNN" and the old one's status becomes "superseded by NNNN".
2. Copy `docs/adr/0000-template.md` to the next free number with a kebab-case title.
3. Context: the forces and constraints, including alternatives considered (1–2 lines each, why rejected).
4. Decision: full sentences, specific enough that a coding agent can follow it without asking.
5. Consequences: what gets easier, harder, and what must be watched.
6. Keep it under one screen. Status `proposed` until the maintainer accepts it.
7. If the decision changes anything in `docs/ARCHITECTURE.md`, update it in the same commit: `docs(adr): NNNN <title>`.
