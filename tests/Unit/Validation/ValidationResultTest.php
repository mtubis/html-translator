<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Validation;

use HtmlTranslator\Validation\ValidationResult;
use HtmlTranslator\Validation\Violation;
use HtmlTranslator\Validation\ViolationType;
use PHPUnit\Framework\Attributes\CoversClass;
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
}
