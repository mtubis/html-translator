<?php

declare(strict_types=1);

/*
 * Regenerates expected.pseudo.html of golden fixtures. Never run by CI.
 *
 * Usage: composer fixtures:update [-- <name> ...]
 * Always review the generated files by hand: a wrong expected file hides the bug a fixture should catch.
 * A result with issues is never written; fix the bug (or the fixture) first.
 */

use HtmlTranslator\Issue;
use HtmlTranslator\Tests\Support\GoldenFixtures;

require __DIR__ . '/../vendor/autoload.php';

$fixtures = GoldenFixtures::all();
$names = array_slice($argv, 1);

$unknown = array_diff($names, array_keys($fixtures));
if ([] !== $unknown) {
    fwrite(STDERR, sprintf("Unknown fixture(s): %s. Known: %s\n", implode(', ', $unknown), implode(', ', array_keys($fixtures))));
    exit(1);
}

$failed = false;
foreach ([] === $names ? $fixtures : array_intersect_key($fixtures, array_flip($names)) as $name => $directory) {
    $result = GoldenFixtures::translate($directory);

    if ([] !== $result->issues() || !$result->isComplete()) {
        fwrite(STDERR, sprintf("Not written: %s has issues:\n%s\n", $name, implode("\n", array_map(
            static fn(Issue $issue): string => sprintf('  %s %s: %s', $issue->unitId, $issue->type->name, $issue->message),
            $result->issues(),
        ))));
        $failed = true;

        continue;
    }

    if (GoldenFixtures::expected($directory) === $result->html()) {
        fwrite(STDOUT, sprintf("Unchanged %s\n", $name));

        continue;
    }

    if (false === file_put_contents($directory . '/' . GoldenFixtures::EXPECTED, $result->html())) {
        fwrite(STDERR, sprintf("Cannot write the expected file of %s.\n", $name));
        $failed = true;

        continue;
    }

    fwrite(STDOUT, sprintf("Updated %s - review it by hand.\n", $name));
}

exit($failed ? 1 : 0);
