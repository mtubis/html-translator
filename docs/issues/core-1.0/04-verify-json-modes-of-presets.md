# Verify the JSON modes of the OpenAI-compatible presets

## Context
#54 introduced `JsonMode` and switched `openAi()` to `JsonSchema` and `lmStudio()` to `JsonSchemaOnly`. The other presets still use `JsonObject` without having been verified:
- Gemini's OpenAI-compatible endpoint has not been checked for `response_format: json_object`.
- `json_schema` works locally on Ollama 0.40.3 but is not the Ollama preset's mode.
- vLLM and Gemini were not tried with `json_schema` at all.
- The `json_schema` wire format was never verified live against OpenAI or LM Studio.

## Scope
- Run `composer test:integration` and `tools/eval` (golden fixtures) per preset, in `JsonObject` and `JsonSchema` mode where the server supports it. Record the results in the PR.
- Switch each preset to the strongest mode that works, update the preset table in `docs/providers.md` and note the server versions checked.
- If a server rejects a mode, the preset keeps or falls back to one that works, and the docs say why.

## Acceptance criteria
- [ ] Every preset's JSON mode is verified against a live server, with the version noted in `docs/providers.md`.
- [ ] Presets that support `json_schema` use it, and eval results do not regress (compare several runs, since single runs are noisy).
- [ ] `composer check` green.

## Notes
Needs API keys (OpenAI, Gemini) and local servers (Ollama, vLLM, LM Studio) in `.env`. The maintainer runs or provides them.

## Out of scope
New presets.
