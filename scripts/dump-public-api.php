<?php

declare(strict_types=1);

/*
 * Regenerates tests/Unit/Architecture/public-api.txt, the snapshot of the public API (ADR-0018).
 * Run it after an intended change of a public signature and commit the diff.
 */

use HtmlTranslator\Tests\Support\PublicApiDump;

require __DIR__ . '/../vendor/autoload.php';

$dump = new PublicApiDump();
$classes = $dump->classesIn(__DIR__ . '/../src', 'HtmlTranslator\\');
$target = __DIR__ . '/../tests/Unit/Architecture/public-api.txt';

file_put_contents($target, $dump->dump($classes));
fwrite(STDOUT, 'Wrote ' . realpath($target) . "\n");

$references = $dump->internalReferences($classes);
foreach ($references as $reference) {
    fwrite(STDERR, "Public signature refers to an internal class: {$reference}\n");
}

exit([] === $references ? 0 : 1);
