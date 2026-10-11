# OpenAI-compatible response fixtures

Bodies of `POST /chat/completions` responses, reconstructed from the documented formats of OpenAI, Azure OpenAI,
Ollama and Gemini's OpenAI-compatible endpoint (no live recordings: the repository holds no API keys). They are
checked against real APIs by the integration tests (#30); replace them with scrubbed recordings when available.

`error-401.json` contains `OpenAiCompatibleChatModelTest::API_KEY` in full, as a server or proxy might echo it, to
test redaction. OpenAI itself masks all but the last characters of a rejected key; `redact()` only removes full copies.
