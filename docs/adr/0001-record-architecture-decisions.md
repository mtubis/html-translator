# 0001. Record architecture decisions

- Status: accepted
- Date: 2026-10-10

## Context
The library is developed largely by coding agents (Claude Code) steered by one maintainer. Agents start every session without memory; decisions that live only in chat history get silently re-litigated or contradicted.

## Decision
Every significant design decision is recorded as a short ADR in `docs/adr/`, numbered sequentially, using `0000-template.md`. `CLAUDE.md` instructs agents to read relevant ADRs before planning and to propose a new ADR instead of deviating from an accepted one.

## Consequences
- Agents make consistent decisions across sessions.
- Changing direction is cheap and explicit: write a new ADR that supersedes the old one.
