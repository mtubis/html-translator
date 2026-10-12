<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Contract;

use HtmlTranslator\Domain\InlineTag;
use HtmlTranslator\Domain\Locale;
use HtmlTranslator\Domain\LocalePair;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Domain\UnitId;
use HtmlTranslator\Domain\UnitText;
use HtmlTranslator\Domain\UnitTextToken;
use HtmlTranslator\Profile\Glossary;
use HtmlTranslator\Profile\GlossaryEntry;
use HtmlTranslator\Profile\ProfileText;
use HtmlTranslator\Profile\TranslationProfile;
use HtmlTranslator\Provider\BatchContext;
use HtmlTranslator\Provider\BatchResult;
use HtmlTranslator\Provider\Correction;
use HtmlTranslator\Provider\Testing\PseudoLocalizationProvider;
use HtmlTranslator\Provider\Testing\TranslationProviderContractTestCase;
use HtmlTranslator\Provider\TranslationBatch;
use HtmlTranslator\Provider\TranslationProviderInterface;
use HtmlTranslator\TranslationOptions;
use HtmlTranslator\Validation\StructureValidator;
use HtmlTranslator\Validation\TagNesting;
use HtmlTranslator\Validation\ValidationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(PseudoLocalizationProvider::class)]
#[UsesClass(TranslationProviderContractTestCase::class)]
#[UsesClass(TranslationOptions::class)]
#[UsesClass(InlineTag::class)]
#[UsesClass(Locale::class)]
#[UsesClass(LocalePair::class)]
#[UsesClass(TranslationUnit::class)]
#[UsesClass(UnitId::class)]
#[UsesClass(UnitText::class)]
#[UsesClass(UnitTextToken::class)]
#[UsesClass(BatchResult::class)]
#[UsesClass(Correction::class)]
#[UsesClass(TranslationBatch::class)]
#[UsesClass(StructureValidator::class)]
#[UsesClass(TagNesting::class)]
#[UsesClass(ValidationResult::class)]
#[UsesClass(Glossary::class)]
#[UsesClass(TranslationProfile::class)]
#[UsesClass(GlossaryEntry::class)]
#[UsesClass(ProfileText::class)]
#[UsesClass(BatchContext::class)]
final class PseudoLocalizationProviderContractTest extends TranslationProviderContractTestCase
{
    protected function provider(): TranslationProviderInterface
    {
        return new PseudoLocalizationProvider();
    }
}
