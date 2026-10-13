# Configuration from untyped arrays fails with InvalidArgumentException

## Context
The Symfony bundle and the Laravel package will build configs from YAML or PHP arrays. Typed callers get PHPStan's help; array configs do not. Today a wrong element type ends in a `TypeError`, which does not implement `TranslatorException`. Examples:
- a non-string in `AnthropicConfig::$betas` or `$extraHeaders`;
- a numeric glossary target in `Glossary::fromArray()`;
- an `int` in `withDoNotTranslate(...)` under `strict_types`.

The #55 review raised this and left it out of scope.

## Scope
- Every public constructor and named constructor that takes an array validates its element types and throws `InvalidArgumentException` naming the parameter and the offending key. This covers the configs, `Glossary::fromArray()` and `TranslationProfile`.
- One test per entry point with a non-string element and with a non-list where a list is expected.
- Document in `docs/providers.md` and `docs/profiles.md` that configs can be built from arrays.

## Acceptance criteria
- [ ] No public entry point throws `TypeError` for a wrong element type in an array argument.
- [ ] Error messages name the parameter and key and quote values with `UntrustedText`; secrets are never quoted.
- [ ] `composer check` green.

## Out of scope
A config tree or schema for the framework packages; they live in their own repositories.
