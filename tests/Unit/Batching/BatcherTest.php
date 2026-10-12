<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Batching;

use HtmlTranslator\Batching\Batcher;
use HtmlTranslator\Batching\CharacterTokenEstimator;
use HtmlTranslator\Batching\TokenEstimatorInterface;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Domain\UnitKind;
use HtmlTranslator\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Batcher::class)]
#[UsesClass(CharacterTokenEstimator::class)]
#[UsesClass(TranslationUnit::class)]
#[UsesClass(UnitId::class)]
#[UsesClass(InvalidArgumentException::class)]
final class BatcherTest extends TestCase
{
    #[Test]
    public function it_returns_no_batches_for_no_units(): void
    {
        self::assertSame([], $this->batcher()->batches([], 100, 20));
    }

    #[Test]
    public function it_puts_units_that_fit_into_one_batch(): void
    {
        self::assertSame([['aaa', 'bb', 'c']], $this->texts($this->batcher()->batches($this->units('aaa', 'bb', 'c'), $this->budget(6, 3), 20)));
    }

    #[Test]
    public function it_starts_a_new_batch_when_the_next_unit_would_exceed_the_budget(): void
    {
        $batches = $this->batcher()->batches($this->units('aaa', 'bb', 'c', 'dddd'), $this->budget(5, 2), 20);

        self::assertSame([['aaa', 'bb'], ['c', 'dddd']], $this->texts($batches));
    }

    #[Test]
    public function it_starts_a_new_batch_when_the_next_unit_would_exceed_the_budget_by_one_token(): void
    {
        $batches = $this->batcher()->batches($this->units('aa', 'bb', 'cc'), $this->budget(3, 2), 20);

        self::assertSame([['aa'], ['bb'], ['cc']], $this->texts($batches));
    }

    #[Test]
    public function it_accepts_the_smallest_limits(): void
    {
        self::assertSame([['a'], ['b']], $this->texts($this->batcher()->batches($this->units('a', 'b'), 1, 1)));
    }

    #[Test]
    public function it_caps_the_units_per_batch(): void
    {
        $batches = $this->batcher()->batches($this->units('a', 'b', 'c', 'd', 'e'), 1000, 2);

        self::assertSame([['a', 'b'], ['c', 'd'], ['e']], $this->texts($batches));
    }

    #[Test]
    public function it_gives_a_unit_over_the_budget_a_batch_of_its_own(): void
    {
        $batches = $this->batcher()->batches($this->units('a', 'b', str_repeat('x', 50), 'c', 'd'), $this->budget(2, 2), 20);

        self::assertSame([['a', 'b'], [str_repeat('x', 50)], ['c', 'd']], $this->texts($batches));
    }

    #[Test]
    public function it_gives_a_first_unit_over_the_budget_a_batch_of_its_own(): void
    {
        $batches = $this->batcher()->batches($this->units(str_repeat('x', 50), 'a'), $this->budget(1, 1), 20);

        self::assertSame([[str_repeat('x', 50)], ['a']], $this->texts($batches));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function invalidLimits(): iterable
    {
        yield 'no budget' => [0, 20];
        yield 'negative budget' => [-1, 20];
        yield 'no units' => [100, 0];
    }

    #[Test]
    #[DataProvider('invalidLimits')]
    public function it_rejects_limits_below_one(int $tokenBudget, int $maxUnits): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->batcher()->batches($this->units('a'), $tokenBudget, $maxUnits);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function documents(): iterable
    {
        yield 'short labels' => [array_map(static fn(int $i): string => 'Label ' . $i, range(1, 60))];
        yield 'long paragraphs' => [array_map(static fn(int $i): string => str_repeat('A sentence of a long paragraph. ', 10 + $i), range(1, 40))];
        yield 'mixed lengths' => [array_map(static fn(int $i): string => str_repeat('word ', 1 + ($i * 37) % 400), range(1, 50))];
    }

    /**
     * @param list<string> $texts
     */
    #[Test]
    #[DataProvider('documents')]
    public function it_never_splits_or_reorders_units(array $texts): void
    {
        $units = $this->units(...$texts);

        self::assertSame($units, array_merge(...new Batcher()->batches($units, 2000, 100)));
    }

    /**
     * @param list<string> $texts
     */
    #[Test]
    #[DataProvider('documents')]
    public function it_keeps_every_batch_of_several_units_within_the_budget(array $texts): void
    {
        $estimator = new CharacterTokenEstimator();

        foreach (new Batcher($estimator)->batches($this->units(...$texts), 2000, 100) as $batch) {
            if (\count($batch) > 1) {
                $tokens = array_sum(array_map(static fn(TranslationUnit $unit): int => $estimator->estimate($unit->text) + Batcher::UNIT_OVERHEAD, $batch));
                self::assertLessThanOrEqual(2000, $tokens);
            }
        }
    }

    #[Test]
    public function it_needs_fewer_calls_than_fixed_chunks_for_short_units(): void
    {
        $units = $this->units(...array_map(static fn(int $i): string => 'Label ' . $i, range(1, 60)));

        // Fixed chunks of batchSize: 20 would take 3 calls.
        self::assertCount(1, new Batcher()->batches($units, 2000, 100));
    }

    #[Test]
    public function it_splits_long_units_that_fixed_chunks_would_send_together(): void
    {
        $units = $this->units(...array_fill(0, 20, str_repeat('A sentence of a long paragraph. ', 40)));

        self::assertGreaterThan(1, \count(new Batcher()->batches($units, 2000, 20)));
    }

    private function batcher(): Batcher
    {
        // One token per byte keeps the arithmetic of the tests exact.
        return new Batcher(new class implements TokenEstimatorInterface {
            public function estimate(string $text): int
            {
                return \strlen($text);
            }
        });
    }

    /**
     * A budget for $units units with $characters characters in total, including the envelope overhead of each.
     */
    private function budget(int $characters, int $units): int
    {
        return $characters + $units * Batcher::UNIT_OVERHEAD;
    }

    /**
     * @return list<TranslationUnit>
     */
    private function units(string ...$texts): array
    {
        $units = [];
        foreach (array_values($texts) as $index => $text) {
            $units[] = TranslationUnit::fromText(UnitId::fromString('u' . ($index + 1)), UnitKind::Block, $text);
        }

        return $units;
    }

    /**
     * @param list<list<TranslationUnit>> $batches
     *
     * @return list<list<string>>
     */
    private function texts(array $batches): array
    {
        return array_map(static fn(array $batch): array => array_map(static fn(TranslationUnit $unit): string => $unit->text, $batch), $batches);
    }
}
