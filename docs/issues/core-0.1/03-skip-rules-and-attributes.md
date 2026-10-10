# SkipRules and TranslatableAttributes

## Context
ARCHITECTURE §6.2. Both must be configurable and have safe defaults.

## Scope
- `Html\SkipRules` — decides whether an element (and its subtree) is excluded. Defaults: `script`, `style`, `code`, `pre`, `kbd`, `samp`, `var`, `svg`, `math`, `template`, `[translate="no"]`, `.notranslate`. `translate="yes"` on a descendant re-enables translation (per HTML spec).
- Immutable API: `SkipRules::defaults()->withElement('x-foo')->withoutElement('code')->withClass('no-i18n')`.
- `Html\TranslatableAttributes` — decides which attributes of which elements become attribute units. Defaults listed in ARCHITECTURE §6.2, including the `meta[name|property]` → `content` cases.

## Acceptance criteria
- [ ] Table-driven unit tests for every default rule and for overrides.
- [ ] `translate="no"` / `translate="yes"` inheritance tested with nested markup.
- [ ] `composer check` green.
