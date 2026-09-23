<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Protocol;

/**
 * Applies RFC 6902 JSON Patch to PHP data, atomically: when one operation
 * fails, the document is returned unchanged and the error says which.
 *
 * Used to keep a run's shared state and activity content the way a client
 * sees it after STATE_DELTA and ACTIVITY_DELTA.
 */
final class JsonPatch
{
    /**
     * @param list<mixed> $operations
     * @throws \InvalidArgumentException when an operation does not apply
     */
    public static function apply(mixed $document, array $operations): mixed
    {
        // Work on a decoded copy: objects as \stdClass, so {} and [] stay apart.
        $working = json_decode(Json::encode($document), false);
        foreach ($operations as $index => $operation) {
            $op = Json::members($operation);
            try {
                $working = self::operation($working, $op);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException(sprintf('Operation %d (%s) does not apply: %s', $index, is_string($op['op'] ?? null) ? $op['op'] : '?', $e->getMessage()), 1758700150, $e);
            }
        }
        return Json::toPhp($working);
    }

    /**
     * @param array<string, mixed> $op
     */
    private static function operation(mixed $document, array $op): mixed
    {
        $path = self::segments($op['path'] ?? null);
        $value = json_decode(Json::encode($op['value'] ?? null), false);
        $name = $op['op'] ?? null;
        if ($name === 'move' || $name === 'copy') {
            $from = self::segments($op['from'] ?? null);
            // Read before removing: containers are changed in place.
            $moved = json_decode(Json::encode(self::get($document, $from)), false);
            return self::add($name === 'move' ? self::remove($document, $from) : $document, $path, $moved);
        }
        return match ($name) {
            'add' => self::add($document, $path, $value),
            'remove' => self::remove($document, $path),
            'replace' => $path === [] ? $value : self::add(self::remove($document, $path), $path, $value),
            'test' => self::equal(self::get($document, $path), $value) ? $document : throw new \InvalidArgumentException('the value differs'),
            default => throw new \InvalidArgumentException('unknown operation'),
        };
    }

    /**
     * @return list<string>
     */
    private static function segments(mixed $pointer): array
    {
        if (!is_string($pointer) || ($pointer !== '' && !str_starts_with($pointer, '/'))) {
            throw new \InvalidArgumentException('not a JSON Pointer');
        }
        if ($pointer === '') {
            return [];
        }
        return array_map(
            static fn(string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment),
            explode('/', substr($pointer, 1)),
        );
    }

    /**
     * @param list<string> $path
     */
    private static function get(mixed $document, array $path): mixed
    {
        foreach ($path as $segment) {
            if ($document instanceof \stdClass && property_exists($document, $segment)) {
                $document = $document->{$segment};
            } elseif (is_array($document) && self::isIndex($segment) && array_key_exists((int)$segment, $document)) {
                $document = $document[(int)$segment];
            } else {
                throw new \InvalidArgumentException(sprintf('"%s" does not exist', $segment));
            }
        }
        return $document;
    }

    /**
     * @param list<string> $path
     */
    private static function add(mixed $document, array $path, mixed $value): mixed
    {
        if ($path === []) {
            return $value;
        }
        return self::at($document, $path, static function (mixed $parent, string $key) use ($value): mixed {
            if ($parent instanceof \stdClass) {
                $parent->{$key} = $value;
                return $parent;
            }
            if (is_array($parent)) {
                $index = $key === '-' ? count($parent) : (self::isIndex($key) ? (int)$key : -1);
                if ($index < 0 || $index > count($parent)) {
                    throw new \InvalidArgumentException(sprintf('index "%s" is out of range', $key));
                }
                array_splice($parent, $index, 0, [$value]);
                return $parent;
            }
            throw new \InvalidArgumentException('the parent is not a container');
        });
    }

    /**
     * @param list<string> $path
     */
    private static function remove(mixed $document, array $path): mixed
    {
        if ($path === []) {
            throw new \InvalidArgumentException('the root cannot be removed');
        }
        return self::at($document, $path, static function (mixed $parent, string $key): mixed {
            if ($parent instanceof \stdClass && property_exists($parent, $key)) {
                unset($parent->{$key});
                return $parent;
            }
            if (is_array($parent) && self::isIndex($key) && array_key_exists((int)$key, $parent)) {
                array_splice($parent, (int)$key, 1);
                return $parent;
            }
            throw new \InvalidArgumentException(sprintf('"%s" does not exist', $key));
        });
    }

    /**
     * Rebuild the path down to the parent of its last segment and let
     * `$change` alter that parent.
     *
     * @param non-empty-list<string> $path
     * @param \Closure(mixed, string): mixed $change
     */
    private static function at(mixed $node, array $path, \Closure $change): mixed
    {
        $key = array_shift($path);
        if ($path === []) {
            return $change($node, $key);
        }
        if ($node instanceof \stdClass && property_exists($node, $key)) {
            $node->{$key} = self::at($node->{$key}, $path, $change);
            return $node;
        }
        if (is_array($node) && self::isIndex($key) && array_key_exists((int)$key, $node)) {
            $node[(int)$key] = self::at($node[(int)$key], $path, $change);
            return $node;
        }
        throw new \InvalidArgumentException(sprintf('"%s" does not exist', $key));
    }

    private static function isIndex(string $segment): bool
    {
        return $segment === '0' || preg_match('/^[1-9]\d*$/', $segment) === 1;
    }

    private static function equal(mixed $a, mixed $b): bool
    {
        return Json::encode(self::canonical($a)) === Json::encode(self::canonical($b));
    }

    private static function canonical(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $members = array_map(self::canonical(...), get_object_vars($value));
            ksort($members);
            return (object)$members;
        }
        return is_array($value) ? array_map(self::canonical(...), $value) : $value;
    }
}
