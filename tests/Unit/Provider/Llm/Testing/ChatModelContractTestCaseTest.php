<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Unit\Provider\Llm\Testing;

use HtmlTranslator\Exception\LogicException;
use HtmlTranslator\Provider\Llm\ChatModelInterface;
use HtmlTranslator\Provider\Llm\Testing\ChatModelContractTestCase;
use HtmlTranslator\Provider\Llm\Testing\ChatScenario;
use HtmlTranslator\Provider\Llm\Testing\RecordedChatRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The contract runs next to other tests in adapter packages, so it must not change the PHP settings they run with.
 * Its lifecycle is driven directly: running a nested test case would go through the runner's internals.
 */
#[CoversClass(ChatModelContractTestCase::class)]
final class ChatModelContractTestCaseTest extends TestCase
{
    private const string SETTING = 'zend.exception_ignore_args';

    #[Test]
    public function it_records_trace_arguments_during_a_contract_test_and_restores_the_setting_afterwards(): void
    {
        $previous = ini_set(self::SETTING, '1');
        $contract = new class ('it_has_a_stable_model_id') extends ChatModelContractTestCase {
            public function begin(): void
            {
                $this->setUp();
            }

            public function end(): void
            {
                $this->tearDown();
            }

            protected function modelFor(ChatScenario $scenario): ChatModelInterface
            {
                throw new LogicException('Not used: only the lifecycle runs.');
            }

            protected function recordedRequest(): RecordedChatRequest
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
