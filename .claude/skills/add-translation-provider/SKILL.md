---
name: add-translation-provider
description: Use when adding support for a new LLM vendor or model endpoint (a ChatModelInterface adapter) or a new non-LLM translation engine (a TranslationProviderInterface implementation).
---

# Add a translation provider or chat model

First decide which port (ADR-0005):
- **New LLM vendor/endpoint** → implement `Provider\Llm\ChatModelInterface` in `src/Provider/Llm/Bridge/<Vendor>/`. Check first whether the vendor offers an OpenAI-compatible endpoint — if yes, a configuration preset for `OpenAiCompatibleChatModel` may be all that is needed; propose that instead of new code.
- **Non-LLM engine** (DeepL, …) → implement `Provider\TranslationProviderInterface` in `src/Provider/<Engine>/`.

Steps:
1. Read the vendor's current API docs (WebFetch) for the single endpoint you need: request shape, auth header, JSON/structured-output options, error format, rate-limit headers. Note the API version used in the class PHPDoc.
2. Record 3–5 real request/response pairs as JSON fixtures in `tests/Contract/Fixtures/<Vendor>/` (success, JSON-in-code-fence, 429, 5xx, malformed body). Scrub keys and IDs.
3. Create the contract test extending `ChatModelContractTestCase` (or `TranslationProviderContractTestCase`) using a fake PSR-18 client that replays the fixtures. Run it — it must fail.
4. Implement the adapter on PSR-18/PSR-17 only. Configuration via a `final readonly` `<Vendor>Config` value object (base URL, model, timeout, max retries, extra headers). Map vendor errors to `ProviderException` with the HTTP status and vendor error code; never include the API key in messages.
5. Transport retries (429/5xx with backoff + jitter) use the shared retry helper — do not write a new one.
6. Add an integration test in `tests/Integration/<Vendor>/` with `#[Group('integration')]`, skipped when the env var (e.g. `HTML_TRANSLATOR_<VENDOR>_API_KEY`) is missing.
7. `composer check` green, then ask the `contract-reviewer` subagent to review.
8. Document configuration in `docs/providers.md` and the README provider table.
