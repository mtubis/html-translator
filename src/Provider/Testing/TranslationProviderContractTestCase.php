<?php

declare(strict_types=1);

namespace HtmlTranslator\Provider\Testing;

use HtmlTranslator\Domain\InlineTag;
use HtmlTranslator\Domain\InlineTagKind;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Domain\LocalePair;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Domain\UnitKind;
use HtmlTranslator\Exception\ProviderException;
use HtmlTranslator\Profile\Formality;
use HtmlTranslator\Profile\Glossary;
use HtmlTranslator\Profile\TermPolicy;
use HtmlTranslator\Profile\TranslationProfile;
use HtmlTranslator\Provider\BatchResult;
use HtmlTranslator\Provider\Correction;
use HtmlTranslator\Provider\TranslationBatch;
use HtmlTranslator\Provider\TranslationProviderInterface;
use HtmlTranslator\TranslationOptions;
use HtmlTranslator\Validation\StructureValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Every TranslationProviderInterface implementation must pass this (ARCHITECTURE §8), also providers in other
 * packages: it ships in src/ and needs phpunit/phpunit (a suggested dependency). The checks follow the output rules of
 * TranslationProviderInterface; the structure of each translation is checked by StructureValidator, the same validator
 * the core uses, so the pseudo-tag grammar is not re-implemented here (and a provider that breaks tags fails it).
 * Batches are readonly, so "the provider does not change the batch" needs no test.
 *
 * Run it without network: give the provider a fake transport or a fake chat model. A fake that answers with the
 * input text unchanged passes, because unchanged text keeps its structure.
 */
abstract class TranslationProviderContractTestCase extends TestCase
{
    /** A credential the provider is configured with; it must never appear in exception messages, traces or reasons. */
    protected const string API_KEY = 'sk-contract-SECRET-0123456789';

    /** Surrounding whitespace the core strips from units and restores itself (HTML ASCII whitespace). */
    private const string HTML_WHITESPACE = " \t\n\f\r";

    /**
     * A provider configured with API_KEY that translates whatever it is given; it must be usable for several batches.
     */
    abstract protected function provider(): TranslationProviderInterface;

    /**
     * A provider configured with API_KEY whose service cannot be reached (down, authentication failed), ideally with an
     * underlying error that mentions the key; null when the provider has no service, which skips that test.
     */
    protected function unavailableProvider(): ?TranslationProviderInterface
    {
        return null;
    }

    /**
     * A provider configured with API_KEY whose service answers with something it cannot decode; null when that cannot
     * happen, which skips that test.
     */
    protected function providerWithUndecodableAnswers(): ?TranslationProviderInterface
    {
        return null;
    }

    /**
     * The language pair of every batch; override it for a provider that does not support English to Polish.
     */
    protected function locales(): LocalePair
    {
        return LocalePair::of(Locale::fromString('en'), Locale::fromString('pl'));
    }

    /**
     * Whether the provider translates without a source language; when it does not, it must throw ProviderException.
     */
    protected function detectsTheSourceLanguage(): bool
    {
        return true;
    }

    /**
     * Traces with arguments, so a credential passed as an argument would show up in (string) $exception.
     */
    protected function setUp(): void
    {
        parent::setUp();
        ini_set('zend.exception_ignore_args', '0');
    }

    #[Test]
    public function it_translates_units_of_every_kind_with_their_structure(): void
    {
        $batch = $this->batch([
            $this->unit('u1', 'Read the <g1>installation <g2>guide</g2></g1> first.<x3/>Then run <x4/>.', [
                InlineTag::of(1, InlineTagKind::Paired),
                InlineTag::of(2, InlineTagKind::Paired),
                InlineTag::of(3, InlineTagKind::Void),
                InlineTag::of(4, InlineTagKind::Opaque),
            ]),
            $this->unit('u2', 'Tom &amp; Jerry write about the &lt;b&gt; tag.'),
            $this->unit('u3', '<g1>Bold start</g1> and a plain end', [InlineTag::of(1, InlineTagKind::Paired)]),
            $this->unit('a1', 'Company logo', kind: UnitKind::Attribute),
            $this->unit('t1', 'About us', kind: UnitKind::Title),
        ]);

        $this->assertTranslatesEverything($batch, $this->provider()->translate($batch));
    }

    #[Test]
    public function it_translates_a_single_unit(): void
    {
        $batch = $this->batch([$this->unit('u1', 'Hello world')]);

        $this->assertTranslatesEverything($batch, $this->provider()->translate($batch));
    }

    #[Test]
    public function it_translates_a_batch_of_the_default_size(): void
    {
        $units = [];
        for ($i = 1; $i <= new TranslationOptions()->batchSize; ++$i) {
            $units[] = $this->unit('u' . $i, \sprintf('Sentence number %d with <g1>a link</g1>.', $i), [InlineTag::of(1, InlineTagKind::Paired)]);
        }

        $batch = $this->batch($units);

        $this->assertTranslatesEverything($batch, $this->provider()->translate($batch));
    }

    #[Test]
    public function it_translates_several_batches_with_one_instance(): void
    {
        $provider = $this->provider();
        $first = $this->batch([$this->unit('u1', 'Good morning')]);
        $second = $this->batch([$this->unit('u2', 'Good night'), $this->unit('a1', 'Logo', kind: UnitKind::Attribute)]);

        $this->assertTranslatesEverything($first, $provider->translate($first));
        $this->assertTranslatesEverything($second, $provider->translate($second));
    }

    #[Test]
    public function it_translates_without_a_known_source_language(): void
    {
        $batch = new TranslationBatch(LocalePair::of(null, $this->locales()->target), [$this->unit('u1', 'Willkommen auf unserer Website')]);

        if (!$this->detectsTheSourceLanguage()) {
            $this->expectException(ProviderException::class);
        }

        $this->assertTranslatesEverything($batch, $this->provider()->translate($batch));
    }

    #[Test]
    public function it_accepts_corrections(): void
    {
        $batch = $this->batch(
            [
                $this->unit('u1', 'Read the <g1>guide</g1>.', [InlineTag::of(1, InlineTagKind::Paired)]),
                $this->unit('u2', 'Contact us'),
                $this->unit('u3', 'Thank you'),
            ],
            [
                'u1' => new Correction('Przeczytaj <g1>poradnik.', '- Close <g1> with </g1>.'),
                'u2' => Correction::forMissingUnit(),
            ],
        );

        $this->assertTranslatesEverything($batch, $this->provider()->translate($batch));
    }

    /**
     * Providers that cannot be steered ignore the profile; the others must still keep the structure.
     */
    #[Test]
    public function it_translates_a_batch_with_a_profile(): void
    {
        $profile = TranslationProfile::technical()
            ->withFormality(Formality::Formal)
            ->withTermPolicy(TermPolicy::KeepOriginal)
            ->withGlossary(Glossary::fromArray(['link' => 'odnośnik']))
            ->withDoNotTranslate('GitHub')
            ->withInstructions("Address the reader directly.\nUse short sentences.");
        $batch = new TranslationBatch($this->locales(), [
            $this->unit('u1', 'Follow <g1>the link</g1> to GitHub.', [InlineTag::of(1, InlineTagKind::Paired)]),
            $this->unit('a1', 'Company logo', kind: UnitKind::Attribute),
        ], profile: $profile);

        $this->assertTranslatesEverything($batch, $this->provider()->translate($batch));
    }

    #[Test]
    public function it_reports_an_unreachable_service_as_a_provider_exception(): void
    {
        $this->assertFailsWithProviderException(
            $this->unavailableProvider() ?? self::markTestSkipped('This provider has no service that could be unavailable.'),
        );
    }

    #[Test]
    public function it_reports_an_undecodable_answer_as_a_provider_exception(): void
    {
        $this->assertFailsWithProviderException(
            $this->providerWithUndecodableAnswers() ?? self::markTestSkipped('This provider cannot receive undecodable answers.'),
        );
    }

    /**
     * Every unit is translated or failed, nothing else is reported, each translation keeps the structure of its unit
     * without added surrounding whitespace, and no failure reason contains API_KEY. For scenarios where failing units
     * are legitimate; well-behaved batches use assertTranslatesEverything().
     */
    protected function assertFulfilsTheContract(TranslationBatch $batch, BatchResult $result): void
    {
        $ids = array_map(static fn(TranslationUnit $unit): string => $unit->id->toString(), $batch->units);
        $reported = array_keys($result->translations + $result->failures + $result->permanentFailures);
        sort($ids);
        sort($reported);
        self::assertSame($ids, $reported, 'Every unit of the batch must be translated or reported as failed, and nothing else.');

        foreach ($result->failures + $result->permanentFailures as $id => $reason) {
            self::assertStringNotContainsString(self::API_KEY, $reason, \sprintf('The failure reason of unit %s contains the credential.', $id));
        }

        $validator = new StructureValidator();
        foreach ($batch->units as $unit) {
            $id = $unit->id->toString();
            $translation = $result->translations[$id] ?? null;
            if (null === $translation) {
                continue;
            }

            $validation = $validator->validate($unit, $translation);
            self::assertTrue($validation->isValid(), \sprintf("Unit %s: %s\n%s", $id, $translation, $validation->describe()));
            self::assertSame(trim($translation, self::HTML_WHITESPACE), $translation, \sprintf('Unit %s has surrounding whitespace.', $id));
        }
    }

    /**
     * assertFulfilsTheContract() for a batch nothing should fail on: every unit is translated.
     */
    protected function assertTranslatesEverything(TranslationBatch $batch, BatchResult $result): void
    {
        $this->assertFulfilsTheContract($batch, $result);
        self::assertSame([], $result->failures, 'No unit of this batch should fail.');
        self::assertSame([], $result->permanentFailures, 'No unit of this batch should fail.');
    }

    /**
     * @param list<TranslationUnit> $units
     * @param array<string, Correction> $corrections
     */
    protected function batch(array $units, array $corrections = []): TranslationBatch
    {
        return new TranslationBatch($this->locales(), $units, $corrections);
    }

    /**
     * @param list<InlineTag> $tags
     */
    protected function unit(string $id, string $text, array $tags = [], UnitKind $kind = UnitKind::Block): TranslationUnit
    {
        return TranslationUnit::fromText(UnitId::fromString($id), $kind, $text, $tags);
    }

    private function assertFailsWithProviderException(TranslationProviderInterface $provider): void
    {
        try {
            $provider->translate($this->batch([$this->unit('u1', 'Hello world')]));
        } catch (ProviderException $exception) {
            for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
                self::assertStringNotContainsString(self::API_KEY, $current->getMessage(), 'An exception message contains the credential.');
                self::assertStringNotContainsString(self::API_KEY, $current->getTraceAsString(), 'An exception trace contains the credential; mark it #[\SensitiveParameter].');
            }

            return;
        }

        self::fail('Expected a ProviderException.');
    }
}
