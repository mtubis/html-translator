# Contributing

Thanks for your interest! Before opening a PR:

1. Read [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) and the [ADRs](docs/adr/). Larger design changes start as an issue or a proposed ADR.
2. `composer install`, then make your change with tests.
3. `composer check` must pass (code style, Rector, PHPStan level max, PHPUnit).
4. Use [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `chore:`).
5. HTML edge cases are welcome as fixtures in `tests/Fixtures/<name>/` — a minimal `input.html` plus a short README of what must hold.

Integration tests against real model APIs are in the `integration` group and are not run by default:

```bash
HTML_TRANSLATOR_OPENAI_API_KEY=... composer test:integration
```
