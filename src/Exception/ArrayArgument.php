<?php

declare(strict_types=1);

namespace HtmlTranslator\Exception;

use SensitiveParameter;

/**
 * Checks the elements of an array argument at runtime, for callers that build it from configuration (YAML, PHP
 * arrays) where PHPStan cannot help them (#78). A wrong element is an InvalidArgumentException that names the
 * parameter and the key (quoted as untrusted text) and the type of the value, never the value itself, so a secret
 * passed by mistake is not quoted. Secret maps (extra headers) name the position instead of the key.
 *
 * In the Exception namespace, which every namespace may depend on, so Domain and Profile can use it too.
 *
 * @internal used by the public constructors and named constructors that take arrays
 */
final class ArrayArgument
{
    /**
     * @param array<array-key, mixed> $values
     *
     * @throws InvalidArgumentException when $values is not a list or an element is not a string
     *
     * @return list<string>
     */
    public static function stringList(string $parameter, #[SensitiveParameter] array $values): array
    {
        self::assertList($parameter, $values);

        return array_values(self::stringMap($parameter, $values));
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @throws InvalidArgumentException when $values is not a list or an element is not an int
     *
     * @return list<int>
     */
    public static function intList(string $parameter, #[SensitiveParameter] array $values): array
    {
        self::assertList($parameter, $values);
        $ints = [];
        foreach ($values as $key => $value) {
            $ints[] = \is_int($value) ? $value : throw self::wrongElement($parameter, $values, $key, $value, 'an int', false);
        }

        return $ints;
    }

    /**
     * @param array<array-key, mixed> $values
     * @param bool $secret name the position of a wrong element, not its key: the keys of a secret map are not repeated
     *                     in messages about its values
     *
     * @throws InvalidArgumentException when an element is not a string
     *
     * @return array<array-key, string>
     */
    public static function stringMap(string $parameter, #[SensitiveParameter] array $values, bool $secret = false): array
    {
        $strings = [];
        foreach ($values as $key => $value) {
            $strings[$key] = \is_string($value) ? $value : throw self::wrongElement($parameter, $values, $key, $value, 'a string', $secret);
        }

        return $strings;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @throws InvalidArgumentException when an element is neither a string nor null
     *
     * @return array<array-key, ?string>
     */
    public static function nullableStringMap(string $parameter, #[SensitiveParameter] array $values): array
    {
        $strings = [];
        foreach ($values as $key => $value) {
            $strings[$key] = null === $value || \is_string($value) ? $value : throw self::wrongElement($parameter, $values, $key, $value, 'a string or null', false);
        }

        return $strings;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @throws InvalidArgumentException when $values is not a list or an element is not an array
     *
     * @return list<array<array-key, mixed>>
     */
    public static function arrayList(string $parameter, #[SensitiveParameter] array $values): array
    {
        self::assertList($parameter, $values);
        $arrays = [];
        foreach ($values as $key => $value) {
            $arrays[] = \is_array($value) ? $value : throw self::wrongElement($parameter, $values, $key, $value, 'an array', false);
        }

        return $arrays;
    }

    /**
     * @template T of object
     *
     * @param array<array-key, mixed> $values
     * @param class-string<T> $class
     *
     * @throws InvalidArgumentException when $values is not a list or an element is not a $class
     *
     * @return list<T>
     */
    public static function listOf(string $parameter, #[SensitiveParameter] array $values, string $class): array
    {
        self::assertList($parameter, $values);

        return array_values(self::mapOf($parameter, $values, $class));
    }

    /**
     * @template T of object
     *
     * @param array<array-key, mixed> $values
     * @param class-string<T> $class
     *
     * @throws InvalidArgumentException when an element is not a $class
     *
     * @return array<array-key, T>
     */
    public static function mapOf(string $parameter, #[SensitiveParameter] array $values, string $class): array
    {
        $objects = [];
        foreach ($values as $key => $value) {
            $objects[$key] = $value instanceof $class ? $value : throw self::wrongElement($parameter, $values, $key, $value, 'an instance of ' . $class, false);
        }

        return $objects;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @throws InvalidArgumentException
     */
    private static function assertList(string $parameter, #[SensitiveParameter] array $values): void
    {
        $position = 0;
        foreach (array_keys($values) as $key) {
            if ($key !== $position++) {
                throw new InvalidArgumentException(\sprintf('%s must be a list, got key %s.', $parameter, self::key($key)));
            }
        }
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private static function wrongElement(string $parameter, #[SensitiveParameter] array $values, #[SensitiveParameter] int|string $key, #[SensitiveParameter] mixed $value, string $expected, bool $secret): InvalidArgumentException
    {
        $where = $secret
            ? \sprintf('%s entry #%d', $parameter, (int) array_search($key, array_keys($values), true) + 1)
            : \sprintf('%s[%s]', $parameter, self::key($key));

        return new InvalidArgumentException(\sprintf('%s must be %s, got %s.', $where, $expected, get_debug_type($value)));
    }

    private static function key(int|string $key): string
    {
        return \is_int($key) ? (string) $key : '"' . UntrustedText::quote($key, 100) . '"';
    }
}
