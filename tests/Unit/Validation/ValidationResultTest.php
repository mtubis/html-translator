<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Validation;

use HtmlTranslator\Validation\ValidationResult;
use HtmlTranslator\Validation\Violation;
use HtmlTranslator\Validation\ViolationType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValidationResult::class)]
#[CoversClass(Violation::class)]
final class ValidationResultTest extends TestCase
{
    #[Test]
    public function it_is_valid_without_violations(): void
    {
        $result = new ValidationResult([]);

        self::assertTrue($result->isValid());
        self::assertSame([], $result->violations());
        self::assertSame('', $result->describe());
    }

    #[Test]
    public function it_describes_ten_violations_in_full(): void
    {
        $result = new ValidationResult($this->violations(10));

        self::assertSame(10, substr_count($result->describe(), "\n") + 1);
        self::assertStringEndsWith('- Remove tag 10.', $result->describe());
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function overflowingViolations(): iterable
    {
        yield 'one more' => [11, '- …and 1 more problem.'];
        yield 'many more' => [50, '- …and 40 more problems.'];
    }

    #[Test]
    #[DataProvider('overflowingViolations')]
    public function it_describes_at_most_ten_violations_and_counts_the_rest(int $count, string $lastLine): void
    {
        $result = new ValidationResult($this->violations($count));
        $lines = explode("\n", $result->describe());

        self::assertCount(11, $lines);
        self::assertSame('- Remove tag 1.', $lines[0]);
        self::assertSame('- Remove tag 10.', $lines[9]);
        self::assertSame($lastLine, $lines[10]);
        self::assertCount($count, $result->violations(), 'The full list stays available.');
    }

    #[Test]
    public function it_describes_violations_one_per_line(): void
    {
        $violations = [
            new Violation(ViolationType::UnclosedTag, 'Close <g1> with </g1>.'),
            new Violation(ViolationType::MissingTag, 'Add <x2/>: every tag from the source text must appear exactly once.'),
        ];
        $result = new ValidationResult($violations);

        self::assertFalse($result->isValid());
        self::assertSame($violations, $result->violations());
        self::assertSame("- Close <g1> with </g1>.\n- Add <x2/>: every tag from the source text must appear exactly once.", $result->describe());
    }

    /**
     * @return list<Violation>
     */
    private function violations(int $count): array
    {
        return array_map(static fn(int $number): Violation => new Violation(ViolationType::UnknownTag, \sprintf('Remove tag %d.', $number)), range(1, $count));
    }
}
