<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Tools\Eval;

use DateTimeImmutable;
use HtmlTranslator\Tools\Eval\ExitCode;
use HtmlTranslator\Tools\Eval\FixtureResult;
use HtmlTranslator\Tools\Eval\Report;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ExitCodeTest extends TestCase
{
    /**
     * @return iterable<string, array{list<FixtureResult>, bool, int}>
     */
    public static function runs(): iterable
    {
        $complete = new FixtureResult('emoji', true, [], 3, 1, 0, 1.0, 1, null, null);
        $failed = new FixtureResult('table', false, [], 0, 0, 0, 0.1, 1, null, null, 'ProviderException: down');

        yield 'every fixture complete' => [[$complete], false, 0];
        yield 'a fixture failed' => [[$complete, $failed], false, 2];
        yield 'a fixture failed, failures allowed' => [[$complete, $failed], true, 0];
        yield 'no fixture' => [[], false, 0];
    }

    /**
     * @param list<FixtureResult> $results
     */
    #[Test]
    #[DataProvider('runs')]
    public function it_exits_with_2_only_for_failing_fixtures_that_are_not_allowed(array $results, bool $allowFailures, int $expected): void
    {
        self::assertSame($expected, ExitCode::of(new Report('m', '2026-10.1', new DateTimeImmutable(), $results), $allowFailures));
    }
}
