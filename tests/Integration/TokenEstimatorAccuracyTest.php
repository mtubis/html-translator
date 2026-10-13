<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Integration;

use HtmlTranslator\Batching\CharacterTokenEstimator;
use HtmlTranslator\Domain\TranslationUnit;
use HtmlTranslator\Html\HtmlLoader;
use HtmlTranslator\Html\Segmenter;
use HtmlTranslator\Html\SkipRules;
use HtmlTranslator\Html\TranslatableAttributes;
use HtmlTranslator\Tests\Support\GoldenFixtures;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CharacterTokenEstimator against recorded token counts (ADR-0015).
 *
 * The counts were made once with tiktoken's o200k_base encoding (GPT-4o family; other vendors' tokenizers may differ,
 * the bound is checked against o200k_base only) over the unit texts of each golden fixture, as the Segmenter produces them with
 * the default rules, and over a few sentences in other scripts. Regenerate them when a fixture's units change.
 */
#[CoversNothing]
final class TokenEstimatorAccuracyTest extends TestCase
{
    /** Underestimating risks a batch over the model's limits, so the bound allows less error downwards. */
    private const float MIN_RATIO = 0.8;

    /** Overestimating only costs extra calls; short labels are overestimated most. */
    private const float MAX_RATIO = 2.5;

    /** Recorded o200k_base tokens of all unit texts per golden fixture. */
    private const array FIXTURE_TOKENS = [
        'blog-article' => 126,
        'br-inside-sentences' => 47,
        'docs-page-inline-code' => 42,
        'emoji' => 61,
        'entities-and-nbsp' => 63,
        'form-labels-placeholders' => 53,
        'fragment-only' => 26,
        'fragment-table-rows' => 26,
        'images-alt-title' => 28,
        'inline-svg' => 14,
        'long-paragraph' => 696,
        'malformed-html' => 41,
        'many-units' => 182,
        'meta-og-tags' => 27,
        'mixed-content-div' => 38,
        'navigation-menu' => 6,
        'nested-lists' => 21,
        'rtl-target' => 45,
        'table' => 16,
        'translate-no' => 24,
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtures(): iterable
    {
        foreach (GoldenFixtures::all() as $name => $directory) {
            yield $name => [$directory];
        }
    }

    #[Test]
    #[DataProvider('fixtures')]
    public function it_stays_within_the_bound_on_the_golden_fixtures(string $directory): void
    {
        $name = basename($directory);
        $recorded = self::FIXTURE_TOKENS[$name] ?? self::fail(\sprintf('Fixture "%s" has no recorded token count; count its unit texts with o200k_base and add it.', $name));

        $units = new Segmenter()->segment(new HtmlLoader()->load(GoldenFixtures::input($directory)), SkipRules::defaults(), TranslatableAttributes::defaults())->units();
        $estimator = new CharacterTokenEstimator();
        $estimated = array_sum(array_map(static fn(TranslationUnit $unit): int => $estimator->estimate($unit->text), $units));

        $this->assertWithinBound($estimated, $recorded);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function sentences(): iterable
    {
        yield 'Polish' => ['Zażółć gęślą jaźń. Kliknij przycisk, aby zapisać zmiany w ustawieniach konta.', 31];
        yield 'German' => ['Bitte überprüfen Sie Ihre Eingaben, bevor Sie das Formular absenden.', 14];
        yield 'Russian' => ['Нажмите кнопку, чтобы сохранить изменения в настройках учётной записи.', 16];
        yield 'Ukrainian' => ['Будь ласка, перевірте введені дані перед надсиланням форми.', 20];
        yield 'Chinese' => ['点击按钮以保存您帐户设置中的更改。', 12];
        yield 'Japanese' => ['ボタンをクリックして、アカウント設定の変更を保存してください。', 17];
        yield 'Korean' => ['계정 설정의 변경 사항을 저장하려면 버튼을 클릭하세요.', 15];
        yield 'Arabic' => ['انقر على الزر لحفظ التغييرات في إعدادات حسابك.', 16];
        yield 'emoji' => ['Great job 🎉 see you soon 👋 thanks 🙏', 12];
    }

    #[Test]
    #[DataProvider('sentences')]
    public function it_stays_within_the_bound_in_other_scripts(string $text, int $recorded): void
    {
        $this->assertWithinBound(new CharacterTokenEstimator()->estimate($text), $recorded);
    }

    private function assertWithinBound(int $estimated, int $recorded): void
    {
        $ratio = $estimated / $recorded;

        self::assertGreaterThanOrEqual(self::MIN_RATIO, $ratio, \sprintf('Estimated %d tokens, recorded %d.', $estimated, $recorded));
        self::assertLessThanOrEqual(self::MAX_RATIO, $ratio, \sprintf('Estimated %d tokens, recorded %d.', $estimated, $recorded));
    }
}
