# Bootstrap: install dev tooling and make `composer check` green

## Context
The skeleton ships configuration for PHPUnit, PHPStan, PHP-CS-Fixer, Rector and Infection, but `require-dev` is empty and the configs were written without being run. This issue is also the first dry run of the Claude Code workflow (`work-on-issue` skill, hooks, reviewer subagent).

## Scope
- `composer require --dev phpunit/phpunit phpstan/phpstan phpstan/phpstan-strict-rules phpstan/phpstan-phpunit phpstan/extension-installer friendsofphp/php-cs-fixer rector/rector infection/infection`
- Run `composer check` and `composer infection`; fix configuration problems (unknown rule sets, PHPUnit schema, PHPStan options removed/renamed) — prefer fixing config over weakening rules.
- Verify the PostToolUse hook: edit `src/Domain/Locale.php` trivially, confirm cs-fixer runs and PHPStan errors are reported back.

## Acceptance criteria
- [ ] `composer.json` has caret constraints for all dev tools; `composer validate --strict` passes.
- [ ] `composer check` green locally on PHP 8.4.
- [ ] `composer infection` runs; `LocaleTest` kills ≥ 90 % of mutants in `Locale` (add tests if not).
- [ ] CI workflow passes on the first push.
- [ ] Any rule deliberately relaxed is listed in the PR description with a reason.
