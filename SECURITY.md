# Security policy

## Supported versions

| Version | Supported          |
|---------|--------------------|
| 1.x     | :white_check_mark: |
| < 1.0   | :x:                |

Security fixes are released as patch versions of the latest 1.x minor release.

## Reporting a vulnerability

Please do **not** open a public issue for a security problem.

Report it privately through GitHub:
[Report a vulnerability](https://github.com/mtubis/html-translator/security/advisories/new)
(the *Security* tab of the repository, then *Report a vulnerability*).

Include the library version, the PHP version, a minimal HTML input and configuration that reproduce the problem,
and what you expected to happen. You can expect a first reply within 7 days. Once a fix is released, the advisory is
published with credit to the reporter, unless you ask otherwise.

## Scope

Of particular interest are problems with how the library handles untrusted input:

- model output that ends up in the translated document as markup instead of text
  ([ADR-0003](docs/adr/0003-inline-markup-as-numbered-pseudo-tags.md));
- HTML input that breaks the structure of the document or bypasses the validation of a translation;
- configuration or input values that leak into exception messages or logs unquoted.
