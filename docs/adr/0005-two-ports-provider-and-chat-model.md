# 0005. Two ports: TranslationProvider and ChatModel

- Status: accepted
- Date: 2026-10-10

## Context
Prompt construction, the pseudo-tag contract, JSON response parsing and corrective-retry messages are identical for every LLM vendor. Only the HTTP shape differs. At the same time we want non-LLM engines (DeepL, test doubles) to plug in.

## Decision
- `TranslationProviderInterface::translate(TranslationBatch): BatchResult` is the port the core depends on.
- `LlmTranslationProvider` implements it using `PromptBuilder`, `ResponseParser` and a `ChatModelInterface`.
- `ChatModelInterface::complete(ChatRequest): ChatResponse` is implemented by vendor adapters (`OpenAiCompatibleChatModel`, `AnthropicChatModel`, …).

## Consequences
- A new LLM vendor = one small adapter + contract test; prompt behaviour stays identical.
- One `OpenAiCompatibleChatModel` with configurable base URL covers OpenAI, Ollama, vLLM, LM Studio and Gemini's OpenAI-compatible endpoint.
- Framework packages may add adapters to `symfony/ai` or Prism as additional `ChatModelInterface` implementations.
