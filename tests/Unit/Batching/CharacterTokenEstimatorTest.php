<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Batching;

use HtmlTranslator\Batching\CharacterTokenEstimator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CharacterTokenEstimator::class)]
final class CharacterTokenEstimatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function texts(): iterable
    {
        yield 'empty' => ['', 0];
        yield 'three characters per token' => ['abcdef', 2];
        yield 'rounded up' => ['abcdefg', 3];
        yield 'code points, not bytes' => ['żółw', 2];
        yield 'Cyrillic' => ['привет', 2];
        yield 'one token per CJK character' => ['你好世界', 4];
        yield 'kana and hangul' => ['ボタン버튼', 5];
        yield 'mixed scripts' => ['Hello 世界', 4];
        yield 'pseudo-tags' => ['<g1>Hi</g1><x2/>', 10];
        yield 'entities' => ['Fish &amp; chips', 7];
        yield 'emoji' => ['🎉', 2];
        yield 'invalid UTF-8 as other text' => ["\xFF\xFE abc", 2];
    }

    #[Test]
    #[DataProvider('texts')]
    public function it_estimates_tokens_by_script(string $text, int $expected): void
    {
        self::assertSame($expected, new CharacterTokenEstimator()->estimate($text));
    }
}
