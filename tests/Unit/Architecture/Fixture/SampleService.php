<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Architecture\Fixture;

use ArrayObject;

/**
 * Public class of the PublicApiDump fixture.
 */
final readonly class SampleService
{
    public const string NAME = 'sample';

    private const int HIDDEN = 1;

    /**
     * @param ArrayObject<int, int> $items an object default with arguments, which the dump must render in full
     */
    public function __construct(
        public int $limit = 10,
        private SampleMode $mode = SampleMode::Fast,
        public ArrayObject $items = new ArrayObject([1, 2]),
    ) {}

    public static function create(string ...$tags): self
    {
        return new self(\count($tags) + self::HIDDEN);
    }

    public function run(?SampleHelper $helper = null): SampleMode
    {
        return $helper instanceof SampleHelper ? SampleMode::Slow : $this->mode;
    }

    /**
     * @internal for tests of this fixture only
     */
    public function debug(): string
    {
        return self::NAME;
    }
}
