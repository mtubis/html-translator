<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Support;

use HtmlTranslator\Domain\Locale;
use HtmlTranslator\HtmlTranslator;
use HtmlTranslator\Provider\Testing\PseudoLocalizationProvider;
use HtmlTranslator\Provider\TranslationProviderInterface;
use HtmlTranslator\TranslatedDocument;
use HtmlTranslator\TranslationOptions;
use JsonException;
use UnexpectedValueException;

/**
 * Golden fixtures: every directory in tests/Fixtures/ with an input.html is one fixture, translated with
 * PseudoLocalizationProvider and compared with its expected.pseudo.html. An optional options.json sets
 * {"target": "ar", "source": "en"} (default target: pl, source: auto). Shared by GoldenFixturesTest and
 * scripts/update-fixtures.php. Any provider that pseudo-localises like PseudoLocalizationProvider must produce the
 * same expected file (e.g. LlmTranslationProvider on PseudoLocalizingChatModel).
 */
final class GoldenFixtures
{
    public const string DIRECTORY = __DIR__ . '/../Fixtures';

    /** Doctype, <html>, <head> or <body> after optional whitespace and comments: decided without the code under test. */
    private const string DOCUMENT_START = '/^\s*(?:<!--.*?-->\s*)*<(?:!doctype|html|head|body)[\s>\/]/is';

    public const string INPUT = 'input.html';

    public const string EXPECTED = 'expected.pseudo.html';

    private const string DEFAULT_TARGET = 'pl';

    private function __construct() {}

    /**
     * @return array<string, string> fixture name → directory, sorted by name
     */
    public static function all(): array
    {
        $inputs = glob(self::DIRECTORY . '/*/' . self::INPUT);
        if (false === $inputs) {
            throw new UnexpectedValueException(\sprintf('Cannot list fixtures in %s.', self::DIRECTORY));
        }

        $fixtures = [];
        foreach ($inputs as $input) {
            $directory = \dirname($input);
            $fixtures[basename($directory)] = $directory;
        }

        ksort($fixtures);

        return $fixtures;
    }

    public static function input(string $directory): string
    {
        return self::read($directory . '/' . self::INPUT);
    }

    public static function expected(string $directory): ?string
    {
        $file = $directory . '/' . self::EXPECTED;

        return is_file($file) ? self::read($file) : null;
    }

    public static function translate(string $directory, ?TranslationProviderInterface $provider = null): TranslatedDocument
    {
        [$target, $source] = self::options($directory);

        return new HtmlTranslator($provider ?? new PseudoLocalizationProvider())
            ->translate(self::input($directory), Locale::fromString($target), new TranslationOptions(source: null === $source ? null : Locale::fromString($source)));
    }

    /**
     * Fixture inputs are small and trusted; a regex keeps the comparison mode independent of HtmlLoader.
     */
    public static function isFragment(string $html): bool
    {
        return 1 !== preg_match(self::DOCUMENT_START, $html);
    }

    /**
     * @return array{string, ?string} target, source
     */
    private static function options(string $directory): array
    {
        $file = $directory . '/options.json';
        if (!is_file($file)) {
            return [self::DEFAULT_TARGET, null];
        }

        try {
            $options = json_decode(self::read($file), true, 2, \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException(\sprintf('%s is not valid JSON: %s', $file, $exception->getMessage()), 0, $exception);
        }

        if (!\is_array($options) || [] !== array_diff(array_keys($options), ['target', 'source'])) {
            throw new UnexpectedValueException(\sprintf('%s may only contain "target" and "source".', $file));
        }

        $target = \array_key_exists('target', $options) ? $options['target'] : self::DEFAULT_TARGET;
        $source = $options['source'] ?? null;
        if (!\is_string($target) || (null !== $source && !\is_string($source))) {
            throw new UnexpectedValueException(\sprintf('%s: "target" and "source" must be locale strings.', $file));
        }

        return [$target, $source];
    }

    private static function read(string $file): string
    {
        $contents = file_get_contents($file);
        if (false === $contents) {
            throw new UnexpectedValueException(\sprintf('Cannot read %s.', $file));
        }

        return $contents;
    }
}
