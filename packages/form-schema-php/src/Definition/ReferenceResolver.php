<?php

declare(strict_types=1);

namespace FormSchema\Definition;

/**
 * Resolves `var` paths by definition: built-ins (strict about their members), `$root.…`,
 * `$row.…`, then the scope chain (inner rows shadow outer keys). Only the first data key of a
 * path is checked; deeper segments are followed for the target through repeater rows.
 */
final readonly class ReferenceResolver
{
    /** Built-ins and their allowed sub-properties. */
    private const BUILTINS = [
        '$today' => [],
        '$now' => [],
        '$user' => ['name', 'email'],
        '$org' => ['name'],
        '$form' => ['version'],
    ];

    public function __construct(private Definition $definition) {}

    public function resolve(string $path, string $scopeId): Reference
    {
        if ($path === '') {
            return new Reference(Reference::SCOPE);
        }

        $segments = explode('.', $path);
        $head = array_shift($segments);

        return match (true) {
            array_key_exists($head, self::BUILTINS) => $this->builtin($head, $segments),
            $head === '$root' => $this->within($segments, ''),
            $head === '$row' => $scopeId === '' ? Reference::unknown() : $this->within($segments, $scopeId),
            default => $this->element($this->definition->resolveKey($head, $scopeId), $segments),
        };
    }

    /**
     * Resolves a path that must start with a key of exactly this scope (no outer scopes).
     *
     * @param  list<string>  $segments
     */
    private function within(array $segments, string $scopeId): Reference
    {
        if ($segments === []) {
            return new Reference(Reference::SCOPE);
        }

        $head = array_shift($segments);

        return $this->element($this->definition->scope($scopeId)?->element($head), $segments);
    }

    /**
     * @param  list<string>  $rest
     */
    private function element(?Element $element, array $rest): Reference
    {
        if ($element === null) {
            return Reference::unknown();
        }

        return new Reference(Reference::ELEMENT, $element, $this->target($element, $rest));
    }

    /**
     * Follows `<index>.<key>` pairs through repeater rows.
     *
     * @param  list<string>  $rest
     */
    private function target(Element $element, array $rest): ?Element
    {
        if ($rest === []) {
            return $element;
        }

        [$index, $key] = [$rest[0], $rest[1] ?? ''];

        if (! $element->isRepeater() || preg_match('/^(0|[1-9]\d*)$/D', $index) !== 1) {
            return null;
        }

        $child = $this->definition->scope($element->rowScopeId())?->element($key);

        return $child === null ? null : $this->target($child, array_slice($rest, 2));
    }

    /**
     * @param  list<string>  $rest
     */
    private function builtin(string $name, array $rest): Reference
    {
        $allowed = self::BUILTINS[$name] ?? [];

        if ($rest === [] || (count($rest) === 1 && in_array($rest[0], $allowed, true))) {
            return new Reference(Reference::BUILTIN);
        }

        return Reference::unknown();
    }
}
