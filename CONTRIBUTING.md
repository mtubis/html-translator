# Contributing

Thanks for your interest! Before opening a PR:

1. Read [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) and the [ADRs](docs/adr/). Larger design changes start as an issue or a proposed ADR.
2. `composer install`, then make your change with tests.
3. `composer check` must pass (code style, Rector, PHPStan level max, PHPUnit).
4. Use [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `chore:`).
5. HTML edge cases are welcome as fixtures in `tests/Fixtures/<name>/` — a minimal `input.html` plus a short README of what must hold.

Integration tests against real model APIs are in the `integration` group and never run in `composer check` or CI.
Copy `.env.dist` to `.env`, fill in the providers you want (a provider is skipped while its variables are empty) and
load it into the shell:

```bash
set -a; . ./.env; set +a
composer test:integration
```

`tools/eval` compares models over all golden fixtures; see [tools/eval/README.md](tools/eval/README.md).

The examples in the README and in [docs/usage.md](docs/usage.md) are run by the `documentation` test suite: the
`php` code blocks of a file run as one script in document order, and a `text` code block right after one is
the output it must print.
Releases follow [docs/RELEASING.md](docs/RELEASING.md).
