<?php

declare(strict_types=1);

namespace HtmlTranslator\Tests\Support;

use BackedEnum;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionEnum;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use RuntimeException;
use SplFileInfo;
use UnitEnum;

/**
 * Renders the public API of classes as text, by reflection (ADR-0018): every class that is not @internal with its
 * declared public members, and protected ones for classes that can be extended. Members marked @internal are left
 * out. Parameter names are part of the API (callers use named arguments), so they are rendered; attributes and
 * PHPDoc types are not.
 */
final readonly class PublicApiDump
{
    /** On its own docblock line or right after the opening of a one-line docblock. */
    private const string INTERNAL = '/(?:^|\/\*\*)\s*\*?\s*@internal\b/m';

    /** PHPDoc tags whose types are part of a member's contract. */
    private const string TYPE_TAGS = '/@(?:param|return|var|throws)\b[^\n]*/';

    /** Methods PHP declares on every enum; they say nothing about a particular one. */
    private const array ENUM_METHODS = ['cases', 'from', 'tryFrom'];

    /**
     * @return list<class-string> the classes, interfaces and enums of a PSR-4 directory, sorted
     */
    public function classesIn(string $directory, string $namespacePrefix): array
    {
        $classes = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }

            $relative = substr($file->getPathname(), \strlen(rtrim($directory, '/')) + 1, -4);
            $class = $namespacePrefix . str_replace('/', '\\', $relative);
            if (!class_exists($class) && !interface_exists($class) && !enum_exists($class)) {
                // A file that declares nothing under its PSR-4 name (or a trait) would silently be missing from the dump.
                throw new RuntimeException(\sprintf('%s does not declare a class, interface or enum %s.', $file->getPathname(), $class));
            }
            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }

    /**
     * @param list<class-string> $classes
     */
    public function dump(array $classes): string
    {
        $blocks = [];
        foreach ($this->publicClasses($classes) as $class) {
            $blocks[] = implode("\n", [$this->declaration($class), ...array_map(static fn(string $member): string => '    ' . $member, $this->members($class))]);
        }

        return implode("\n\n", $blocks) . "\n";
    }

    /**
     * Public members whose signature names an internal class of the given set: the classification is inconsistent.
     * Native types are read by reflection; PHPDoc types (list<X>, @throws) by the short or full class name in the
     * member's @param, @return, @var and @throws tags.
     *
     * @param list<class-string> $classes
     *
     * @return list<string> "Class::member -> InternalClass"
     */
    public function internalReferences(array $classes): array
    {
        $internal = array_values(array_filter($classes, fn(string $class): bool => $this->isInternal(new ReflectionClass($class))));
        $found = [];

        foreach ($this->publicClasses($classes) as $class) {
            $parent = $class->getParentClass();
            $references = [$class->getShortName() => [...(false === $parent ? [] : [$parent->getName()]), ...$class->getInterfaceNames()]];
            foreach ($this->methods($class) as $method) {
                $names = $this->typeNames($method->getReturnType());
                foreach ($method->getParameters() as $parameter) {
                    $names = [...$names, ...$this->typeNames($parameter->getType())];
                }
                $references[$method->getName() . '()'] = [...$names, ...$this->namedInDocs($method, $internal)];
            }
            foreach ($this->properties($class) as $property) {
                $references['$' . $property->getName()] = [...$this->typeNames($property->getType()), ...$this->namedInDocs($property, $internal)];
            }
            foreach ($this->constants($class) as $constant) {
                $references[$constant->getName()] = $this->namedInDocs($constant, $internal);
            }

            foreach ($references as $member => $names) {
                foreach (array_intersect($names, $internal) as $name) {
                    $found[] = \sprintf('%s::%s -> %s', $class->getName(), $member, $name);
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @param list<class-string> $classes
     *
     * @return list<ReflectionClass<object>>
     */
    private function publicClasses(array $classes): array
    {
        return array_values(array_filter(
            array_map(static fn(string $class): ReflectionClass => new ReflectionClass($class), $classes),
            fn(ReflectionClass $class): bool => !$this->isInternal($class),
        ));
    }

    /**
     * @param ReflectionClass<object>|ReflectionMethod|ReflectionProperty|ReflectionClassConstant $element
     */
    private function isInternal(ReflectionClass|ReflectionMethod|ReflectionProperty|ReflectionClassConstant $element): bool
    {
        $comment = $element->getDocComment();

        return false !== $comment && 1 === preg_match(self::INTERNAL, $comment);
    }

    /**
     * @param ReflectionClass<object> $class
     */
    private function declaration(ReflectionClass $class): string
    {
        $kind = match (true) {
            $class->isEnum() => 'enum',
            $class->isInterface() => 'interface',
            default => $this->words([
                $class->isAbstract() ? 'abstract' : '',
                $class->isFinal() ? 'final' : '',
                $class->isReadOnly() ? 'readonly' : '',
                'class',
            ]),
        };
        $declaration = $kind . ' ' . $class->getName();

        $enum = $this->enum($class);
        if ($enum instanceof ReflectionEnum) {
            $backing = $enum->getBackingType();
            $declaration .= $backing instanceof ReflectionNamedType ? ': ' . $backing : '';
        }

        $parent = $class->getParentClass();
        if (false !== $parent) {
            $declaration .= ' extends ' . $parent->getName();
        }

        // Interfaces that come with the parent class are the parent's API, not this class's.
        $interfaces = array_diff($class->getInterfaceNames(), [UnitEnum::class, BackedEnum::class], false === $parent ? [] : $parent->getInterfaceNames());
        sort($interfaces);
        if ([] !== $interfaces) {
            $declaration .= ($class->isInterface() ? ' extends ' : ' implements ') . implode(', ', $interfaces);
        }

        return $declaration;
    }

    /**
     * @param ReflectionClass<object> $class
     *
     * @return list<string>
     */
    private function members(ReflectionClass $class): array
    {
        $members = [];

        foreach ($this->enum($class)?->getCases() ?? [] as $case) {
            $value = $case->getValue();
            $members[] = 'case ' . $case->getName() . ($value instanceof BackedEnum ? ' = ' . var_export($value->value, true) : '');
        }

        foreach ($this->constants($class) as $constant) {
            $members[] = $this->words([
                $constant->isFinal() && !$class->isFinal() ? 'final' : '',
                $this->visibility($constant),
                'const',
                $this->type($constant->getType()),
                $constant->getName(),
            ]) . ' = ' . $this->value($constant->getValue());
        }

        foreach ($this->properties($class) as $property) {
            $members[] = $this->words([
                $this->visibility($property),
                $this->setVisibility($property),
                $property->isStatic() ? 'static' : '',
                $property->isReadOnly() && !$class->isReadOnly() ? 'readonly' : '',
                $this->type($property->getType()),
                '$' . $property->getName(),
            ]);
        }

        foreach ($this->methods($class) as $method) {
            $members[] = $this->words([
                $method->isAbstract() && !$class->isInterface() ? 'abstract' : '',
                $method->isFinal() && !$class->isFinal() ? 'final' : '',
                $this->visibility($method),
                $method->isStatic() ? 'static' : '',
                'function',
            ]) . ' ' . $method->getName() . '(' . implode(', ', array_map($this->parameter(...), $method->getParameters())) . ')'
                . ($method->getReturnType() instanceof ReflectionType ? ': ' . $this->type($method->getReturnType()) : '');
        }

        return $members;
    }

    /**
     * @param ReflectionClass<object> $class
     *
     * @return ReflectionEnum<UnitEnum>|null
     */
    private function enum(ReflectionClass $class): ?ReflectionEnum
    {
        $name = $class->getName();

        return enum_exists($name) ? new ReflectionEnum($name) : null;
    }

    /**
     * @param ReflectionClass<object> $class
     *
     * @return list<ReflectionMethod>
     */
    private function methods(ReflectionClass $class): array
    {
        $methods = array_filter(
            $class->getMethods(),
            fn(ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class->getName()
                && $this->isVisible($class, $method)
                && !$this->isInternal($method)
                && (!$class->isEnum() || !\in_array($method->getName(), self::ENUM_METHODS, true)),
        );
        usort($methods, static fn(ReflectionMethod $a, ReflectionMethod $b): int => [$a->getName() !== '__construct', $a->getName()] <=> [$b->getName() !== '__construct', $b->getName()]);

        return $methods;
    }

    /**
     * @param ReflectionClass<object> $class
     *
     * @return list<ReflectionProperty>
     */
    private function properties(ReflectionClass $class): array
    {
        if ($class->isEnum()) {
            return [];
        }

        return array_values(array_filter(
            $class->getProperties(),
            fn(ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === $class->getName()
                && $this->isVisible($class, $property)
                && !$this->isInternal($property),
        ));
    }

    /**
     * @param ReflectionClass<object> $class
     *
     * @return list<ReflectionClassConstant>
     */
    private function constants(ReflectionClass $class): array
    {
        return array_values(array_filter(
            $class->getReflectionConstants(),
            fn(ReflectionClassConstant $constant): bool => $constant->getDeclaringClass()->getName() === $class->getName()
                && !$constant->isEnumCase()
                && $this->isVisible($class, $constant)
                && !$this->isInternal($constant),
        ));
    }

    /**
     * @param list<class-string> $internal
     *
     * @return list<string> the internal classes named, by short or full name, in the member's type tags
     */
    private function namedInDocs(ReflectionMethod|ReflectionProperty|ReflectionClassConstant $member, array $internal): array
    {
        $comment = $member->getDocComment();
        if (false === $comment || 0 === preg_match_all(self::TYPE_TAGS, $comment, $tags)) {
            return [];
        }
        $text = implode("\n", $tags[0]);

        return array_values(array_filter($internal, static function (string $name) use ($text): bool {
            $short = substr($name, (int) strrpos($name, '\\') + 1);

            return 1 === preg_match('/(?<![\w\\\\])(?:' . preg_quote($name, '/') . '|' . preg_quote($short, '/') . ')(?!\w)/', $text);
        }));
    }

    /**
     * A narrower set visibility (public private(set)) is part of the API; readonly properties have one implicitly.
     */
    private function setVisibility(ReflectionProperty $property): string
    {
        return match (true) {
            $property->isReadOnly() => '',
            $property->isPrivateSet() => 'private(set)',
            $property->isProtectedSet() && $property->isPublic() => 'protected(set)',
            default => '',
        };
    }

    /**
     * Public members always; protected ones only where a subclass can reach them.
     *
     * @param ReflectionClass<object> $class
     */
    private function isVisible(ReflectionClass $class, ReflectionMethod|ReflectionProperty|ReflectionClassConstant $member): bool
    {
        return $member->isPublic() || ($member->isProtected() && !$class->isFinal());
    }

    private function visibility(ReflectionMethod|ReflectionProperty|ReflectionClassConstant $member): string
    {
        return $member->isPublic() ? 'public' : 'protected';
    }

    private function parameter(ReflectionParameter $parameter): string
    {
        $rendered = trim(implode(' ', [
            $this->type($parameter->getType()),
            ($parameter->isPassedByReference() ? '&' : '') . ($parameter->isVariadic() ? '...' : '') . '$' . $parameter->getName(),
        ]));

        if (!$parameter->isDefaultValueAvailable()) {
            return $rendered;
        }

        // Reflection prints the default as written, arguments of `new` included, which getDefaultValue() would lose.
        if (1 !== preg_match('/ = (.*) \]$/s', (string) $parameter, $default)) {
            throw new RuntimeException(\sprintf('Cannot read the default of $%s.', $parameter->getName()));
        }

        return $rendered . ' = ' . $default[1];
    }

    private function value(mixed $value): string
    {
        return match (true) {
            $value instanceof UnitEnum => $value::class . '::' . $value->name,
            \is_object($value) => 'new ' . $value::class . '()',
            \is_array($value) => '[' . implode(', ', array_map(
                fn(int|string $key, mixed $item): string => (array_is_list($value) ? '' : var_export($key, true) . ' => ') . $this->value($item),
                array_keys($value),
                $value,
            )) . ']',
            null === $value => 'null',
            default => var_export($value, true),
        };
    }

    /**
     * @param list<string> $words
     */
    private function words(array $words): string
    {
        return implode(' ', array_filter($words, static fn(string $word): bool => '' !== $word));
    }

    private function type(?ReflectionType $type): string
    {
        return $type instanceof ReflectionType ? (string) $type : '';
    }

    /**
     * @return list<string>
     */
    private function typeNames(?ReflectionType $type): array
    {
        return match (true) {
            $type instanceof ReflectionNamedType => $type->isBuiltin() ? [] : [$type->getName()],
            $type instanceof ReflectionUnionType, $type instanceof ReflectionIntersectionType => array_merge([], ...array_map($this->typeNames(...), $type->getTypes())),
            default => [],
        };
    }
}
