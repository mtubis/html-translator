<?php

declare(strict_types=1);

/*
 * Translates the golden fixtures with a real model and writes a structural report to tools/eval/out/.
 * See tools/eval/README.md. Calls to hosted APIs cost money.
 */

use HtmlTranslator\Exception\TranslatorException;
use HtmlTranslator\Provider\Llm\PromptBuilder;
use HtmlTranslator\Tests\Support\GoldenFixtures;
use HtmlTranslator\Tests\Support\IntegrationProviders;
use HtmlTranslator\Tests\Support\PseudoLocalizingChatModel;
use HtmlTranslator\Tools\Eval\CallCountingChatModel;
use HtmlTranslator\Tools\Eval\EvalRunner;
use HtmlTranslator\Tools\Eval\FixtureResult;
use HtmlTranslator\Tools\Eval\Report;

require __DIR__ . '/../../vendor/autoload.php';

const USAGE = <<<'TEXT'
    Usage: php tools/eval/run.php --provider=<name> [--model=<model>] [--base-url=<url>] [--fixtures=<a,b>] [--out=<dir>]

      --provider   openai, anthropic, gemini, ollama, vllm, lmstudio, or pseudo (no network, checks the tool itself)
      --model      overrides HTML_TRANSLATOR_<PROVIDER>_MODEL
      --base-url   overrides HTML_TRANSLATOR_<PROVIDER>_BASE_URL (local servers only)
      --fixtures   comma-separated fixture names (default: all)
      --out        report directory (default: tools/eval/out)

    Options take the form --name=value. Load credentials first: set -a; . ./.env; set +a

    TEXT;

/**
 * @param array<array-key, mixed> $options
 */
function option(array $options, string $name): ?string
{
    $value = $options[$name] ?? null;

    return is_string($value) && '' !== trim($value) ? trim($value) : null;
}

// getopt() silently drops unknown options, which would run the default model over every fixture.
foreach (array_slice($argv, 1) as $argument) {
    if (1 !== preg_match('/^--(?:(?:provider|model|base-url|fixtures|out)=.*|help)$/s', $argument)) {
        fwrite(STDERR, sprintf("Unknown argument \"%s\".\n\n%s", $argument, USAGE));
        exit(1);
    }
}

$options = getopt('', ['provider:', 'model:', 'base-url:', 'fixtures:', 'out:', 'help']);
$options = false === $options ? [] : $options;
if (isset($options['help'])) {
    fwrite(STDOUT, USAGE);
    exit(0);
}

$provider = option($options, 'provider');
if (null === $provider) {
    fwrite(STDERR, USAGE);
    exit(1);
}

try {
    if ('pseudo' === $provider) {
        $chatModel = PseudoLocalizingChatModel::create();
    } else {
        $config = IntegrationProviders::config($provider, option($options, 'model'), option($options, 'base-url'));
        if (null === $config) {
            fwrite(STDERR, sprintf("Set %s in .env (see .env.dist) and load it: set -a; . ./.env; set +a\n", IntegrationProviders::requiredVariable($provider)));
            exit(1);
        }

        $chatModel = IntegrationProviders::chatModel($config);
    }
} catch (TranslatorException|UnexpectedValueException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

$fixtures = GoldenFixtures::all();
$selected = option($options, 'fixtures');
if (null !== $selected) {
    $names = array_map(trim(...), explode(',', $selected));
    $unknown = array_diff($names, array_keys($fixtures));
    if ([] !== $unknown) {
        fwrite(STDERR, sprintf("Unknown fixture(s): %s. Known: %s\n", implode(', ', $unknown), implode(', ', array_keys($fixtures))));
        exit(1);
    }

    $fixtures = array_intersect_key($fixtures, array_flip($names));
}

$directory = option($options, 'out') ?? __DIR__ . '/out';
if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
    fwrite(STDERR, sprintf("Cannot create %s.\n", $directory));
    exit(1);
}

$model = new CallCountingChatModel($chatModel);
$startedAt = new DateTimeImmutable();
$results = [];
$base = $directory . '/' . new Report($model->modelId(), PromptBuilder::VERSION, $startedAt, [])->fileName();
fwrite(STDOUT, sprintf("Evaluating %s on %d fixtures (prompt %s)\n", $model->modelId(), count($fixtures), PromptBuilder::VERSION));

// Rewritten after every fixture, so an interrupted run keeps what it has paid for.
new EvalRunner($model)->run($fixtures, static function (FixtureResult $result) use ($model, $startedAt, $base, &$results): void {
    $results[] = $result;
    fwrite(STDOUT, sprintf("  %-28s %s, %d issue(s), %.1fs%s\n", $result->fixture, $result->complete ? 'complete' : 'incomplete', $result->issueCount(), $result->durationSeconds, null === $result->error ? '' : ' — ' . $result->error));

    $report = new Report($model->modelId(), PromptBuilder::VERSION, $startedAt, $results);
    if (false === file_put_contents($base . '.md', $report->toMarkdown()) || false === file_put_contents($base . '.json', $report->toJson())) {
        fwrite(STDERR, sprintf("Cannot write the report to %s.md/.json.\n", $base));
        exit(1);
    }
});

fwrite(STDOUT, sprintf("Report: %s.md (and .json)\n", $base));
