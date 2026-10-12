# tools/eval

Measures how well a real model keeps the structure of the golden fixtures (`tests/Fixtures/`), to compare models and
prompt versions. Only structure is measured, not the quality of the wording. Not part of the package.

```bash
set -a; . ./.env; set +a                       # credentials and models, see .env.dist
php tools/eval/run.php --provider=ollama --model=llama3.1
php tools/eval/run.php --provider=openai --fixtures=blog-article,table
php tools/eval/run.php --provider=pseudo       # no network: checks the tool itself
```

Options: `--provider` (`openai`, `gemini`, `ollama`, `vllm`, `lmstudio`, `pseudo`), `--model` and `--base-url`
(override the `.env` values), `--fixtures` (comma-separated names, default all), `--out` (default `tools/eval/out/`).
`composer eval -- --provider=…` works too.

## Report

`tools/eval/out/<date>-<time>-<model id>.md` and `.json` (the directory is git-ignored), with the prompt version
(`PromptBuilder::VERSION`), the model id, and per fixture:

| Column | Meaning |
|---|---|
| Complete | every unit translated and valid (`TranslatedDocument::isComplete()`) |
| Issues | issues by type, or the error that stopped the translation |
| Units, Batches | from `TranslationStats` |
| Retried | units sent again with feedback |
| Calls | requests to the model, including corrective retries, parse retries and failed requests |
| Tokens in / out | as reported by the model; `–` when it reports none |
| Seconds | wall time of the translation |

Reports contain the model id, never the configuration: no keys or headers. Issue messages may quote short snippets of
model output.

Hosted APIs charge per token; check the token columns of a short run (`--fixtures=table`) before running everything.
The report is rewritten after every fixture, so an interrupted run keeps what it has done.
