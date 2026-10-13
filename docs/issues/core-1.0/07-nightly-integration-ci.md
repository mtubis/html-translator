# Nightly CI job for the integration tests

## Context
`composer test:integration` only runs on a developer's machine, so vendor API changes are noticed late. This was explicitly out of scope of #30.

## Scope
- A scheduled GitHub Actions workflow (nightly, plus manual dispatch) that runs `composer test:integration`. It reads API keys from repository secrets, and providers without a secret are skipped as locally.
- On failure: the job fails and opens or updates one issue labelled `integration`, with the failing tests. It never posts keys or full traces.
- Optional: `tools/eval` on one cheap model, with the report as an artifact (depends on the eval exit-code issue).

## Acceptance criteria
- [ ] The workflow runs on schedule and on dispatch, and skips providers without secrets.
- [ ] No secret appears in logs; the workflow uses minimal permissions.
- [ ] The README or CONTRIBUTING explains how to add the secrets.

## Notes
This issue asks for changes in `.github/`. The maintainer adds the secrets.

## Out of scope
Local servers (Ollama, vLLM, LM Studio) in CI.
