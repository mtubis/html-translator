<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Architecture\Fixture;

/** Backed enum of the PublicApiDump fixture. */
enum SampleMode: string
{
    case Fast = 'fast';
    case Slow = 'slow';
}
