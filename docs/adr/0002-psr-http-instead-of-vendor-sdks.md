# 0002. PSR-18/PSR-17 HTTP instead of vendor SDKs

- Status: accepted
- Date: 2026-10-10

## Context
Supporting OpenAI, Anthropic, Gemini and self-hosted models through their SDKs would pull several heavy, fast-moving dependencies into every installation, and force their HTTP client choices on users. We use a tiny subset of each API (one chat/messages call, JSON output).

## Decision
Chat-model adapters are thin mappings written against PSR-18 (`ClientInterface`) and PSR-17 (request/stream factories). Users supply any compliant client (Symfony HttpClient, Guzzle, …). `php-http/discovery` may be used as an optional convenience for zero-config setup.

## Consequences
- Core install has no vendor SDK dependency; framework packages wire the client they already have.
- We own the request/response mapping and must cover it with contract tests against recorded fixtures.
- New vendor features (e.g. native structured output) are adopted deliberately, adapter by adapter.
