# Anthropic Messages API response fixtures

Bodies of `POST /v1/messages` responses (`anthropic-version: 2023-06-01`), reconstructed from the documented format as
of 2026-10 (no live recordings: the repository holds no API keys). They are checked against the real API by the
integration test; replace them with scrubbed recordings when available.

`success-with-thinking.json` has the empty `thinking` block that current models (e.g. Claude Opus 5.5, thinking
always on, display "omitted" by default) put before the text. `error-401.json` contains
`AnthropicChatModelTest::API_KEY` in full, as a proxy might echo it, to test redaction.
