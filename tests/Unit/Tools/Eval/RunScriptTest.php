<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Tools\Eval;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs tools/eval/run.php as a process with the pseudo provider (no network) to check its exit codes. A failing
 * fixture (exit code 2) needs a real model; ExitCode::of(), which decides it, is tested in ExitCodeTest.
 */
#[CoversNothing]
final class RunScriptTest extends TestCase
{
    private const string SCRIPT = __DIR__ . '/../../../../tools/eval/run.php';

    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        $this->out = sys_get_temp_dir() . '/html-translator-eval-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $files = glob($this->out . '/*');
        foreach (false === $files ? [] : $files as $file) {
            unlink($file);
        }

        if (is_dir($this->out)) {
            rmdir($this->out);
        }

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function usageErrors(): iterable
    {
        yield 'unknown argument' => [['--provider=pseudo', '--fixtures=table', '--allow-failures=yes']];
        yield 'unknown fixture' => [['--provider=pseudo', '--fixtures=no-such-fixture']];
        yield 'missing provider' => [['--fixtures=table']];
        yield 'missing .env variable' => [['--provider=openai', '--fixtures=table']];
        yield 'unknown JSON mode' => [['--provider=ollama', '--model=m', '--json-mode=xml', '--fixtures=table']];
    }

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('usageErrors')]
    public function it_exits_with_1_on_a_usage_error(array $arguments): void
    {
        [$exitCode] = $this->runScript($arguments);

        self::assertSame(1, $exitCode);
    }

    #[Test]
    public function it_exits_with_0_when_every_fixture_is_complete(): void
    {
        [$exitCode, $stdout] = $this->runScript(['--provider=pseudo', '--fixtures=table,emoji', '--out=' . $this->out]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Report: ', $stdout);
        self::assertStringContainsString('- Cache hits: 0 of ', $this->markdownReport());
    }

    #[Test]
    public function it_accepts_the_allow_failures_flag(): void
    {
        [$exitCode] = $this->runScript(['--provider=pseudo', '--fixtures=table', '--allow-failures', '--out=' . $this->out]);

        self::assertSame(0, $exitCode);
    }

    #[Test]
    public function it_reports_the_shared_cache_when_cache_is_passed(): void
    {
        // The sharing itself is tested in EvalRunnerTest.
        [$exitCode] = $this->runScript(['--provider=pseudo', '--fixtures=table,emoji', '--cache', '--out=' . $this->out]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('- Cache: in-memory, shared by the fixtures of this run', $this->markdownReport());
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function runScript(array $arguments): array
    {
        // Files instead of pipes: two pipes could block each other when the script writes a lot to one of them.
        $stdoutFile = tempnam(sys_get_temp_dir(), 'eval-out');
        $stderrFile = tempnam(sys_get_temp_dir(), 'eval-err');
        self::assertIsString($stdoutFile);
        self::assertIsString($stderrFile);

        try {
            // An empty environment: the script must not pick up a real provider from the developer's shell.
            $process = proc_open([\PHP_BINARY, self::SCRIPT, ...$arguments], [1 => ['file', $stdoutFile, 'w'], 2 => ['file', $stderrFile, 'w']], $pipes, null, []);
            self::assertIsResource($process);
            $exitCode = proc_close($process);

            return [$exitCode, (string) file_get_contents($stdoutFile), (string) file_get_contents($stderrFile)];
        } finally {
            unlink($stdoutFile);
            unlink($stderrFile);
        }
    }

    private function markdownReport(): string
    {
        $reports = glob($this->out . '/*.md');
        self::assertIsArray($reports);
        self::assertCount(1, $reports);

        return (string) file_get_contents($reports[0]);
    }
}
