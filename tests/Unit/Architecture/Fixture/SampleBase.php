<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Architecture\Fixture;

/** Extensible class of the PublicApiDump fixture: its protected members are API. */
abstract class SampleBase
{
    public private(set) int $calls = 0;

    /**
     * @return list<SampleHelper> named only in PHPDoc, which PublicApiDump must report too
     */
    public function helpers(): array
    {
        return [];
    }

    abstract protected function name(): string;

    final public function greet(): string
    {
        ++$this->calls;

        return 'Hello ' . $this->name();
    }
}
