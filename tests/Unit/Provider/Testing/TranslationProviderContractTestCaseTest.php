<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Provider\Testing;

use HtmlTranslator\Exception\LogicException;
use HtmlTranslator\Provider\Testing\TranslationProviderContractTestCase;
use HtmlTranslator\Provider\TranslationProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The contract runs next to other tests in provider packages, so it must not change the PHP settings they run with.
 * Its lifecycle is driven directly: running a nested test case would go through the runner's internals.
 */
#[CoversClass(TranslationProviderContractTestCase::class)]
final class TranslationProviderContractTestCaseTest extends TestCase
{
    private const string SETTING = 'zend.exception_ignore_args';

    #[Test]
    public function it_records_trace_arguments_during_a_contract_test_and_restores_the_setting_afterwards(): void
    {
        $previous = ini_set(self::SETTING, '1');
        $contract = new class ('it_translates_a_single_unit') extends TranslationProviderContractTestCase {
            public function begin(): void
            {
                $this->setUp();
            }

            public function end(): void
            {
                $this->tearDown();
            }

            protected function provider(): TranslationProviderInterface
            {
                throw new LogicException('Not used: only the lifecycle runs.');
            }
        };

        try {
            $contract->begin();
            self::assertSame('0', \ini_get(self::SETTING));

            $contract->end();
            self::assertSame('1', \ini_get(self::SETTING));
        } finally {
            ini_set(self::SETTING, false === $previous ? '0' : $previous);
        }
    }
}
