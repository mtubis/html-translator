<?php

declare(strict_types=1);

namespace HtmlTranslator\Tools\Eval;

/**
 * Exit codes of tools/eval/run.php. Failing fixtures get their own code, so CI can tell a model or prompt that got
 * worse from a run that could not start.
 */
final class ExitCode
{
    public const int OK = 0;

    public const int USAGE_OR_SETUP_ERROR = 1;

    public const int FIXTURES_FAILED = 2;

    public static function of(Report $report, bool $allowFailures): int
    {
        return $report->hasFailures() && !$allowFailures ? self::FIXTURES_FAILED : self::OK;
    }
}
