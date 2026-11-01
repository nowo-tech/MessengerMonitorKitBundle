<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Payload;

use BackedEnum;
use Closure;
use DateTimeInterface;
use ReflectionClass;
use ReflectionObject;
use ReflectionProperty;
use UnitEnum;

use function array_slice;
use function count;
use function get_debug_type;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_resource;
use function is_string;
use function mb_check_encoding;
use function mb_strlen;
use function mb_substr;
use function method_exists;
use function spl_object_id;
use function sprintf;

/**
 * Turns a message object into a bounded array of scalars for the payload view: public, protected and private
 * properties (parents included), at most `payload.max_depth` levels, `payload.max_items` items per level and
 * `payload.max_nodes` values in the whole graph, strings cut at `payload.max_string_length`, binary strings, closures
 * and resources replaced by a marker, every object already shown anywhere in the graph replaced by `(ref: Class)`.
 *
 * Nothing is executed: no getter, `__toString()`, serializer or property hook runs on the message. Raw backing values
 * are read (`ReflectionProperty::getRawValue()` on PHP 8.4+), virtual (hook-only) properties are skipped and
 * uninitialised lazy objects are shown as `(lazy: Class)` without being initialised. Stateless between calls.
 */
final readonly class PayloadNormalizer
{
    /** Key holding the class of a normalised object. */
    public const CLASS_KEY = '@class';

    /** Value standing for everything past the node budget. */
    public const BUDGET_MARKER = '(truncated: payload too large)';

    /**
     * @param int $maxDepth nesting levels kept
     * @param int $maxItems items kept per array / object
     * @param int $maxStringLength characters kept per string
     * @param int $maxNodes values kept in the whole graph
     */
    public function __construct(
        private int $maxDepth = 4,
        private int $maxItems = 50,
        private int $maxStringLength = 500,
        private int $maxNodes = 2000,
    ) {
    }

    /**
     * Normalised array of an object (or of any value, wrapped under `value`).
     *
     * @return array<array-key, mixed>
     */
    public function normalize(mixed $value): array
    {
        $seen       = [];
        $nodes      = 0;
        $normalised = $this->value($value, 0, $seen, $nodes);

        return is_array($normalised) ? $normalised : ['value' => $normalised];
    }

    /**
     * @param array<int, true> $seen ids of the objects already shown in the graph
     * @param int $nodes values normalised so far
     */
    private function value(mixed $value, int $depth, array &$seen, int &$nodes): mixed
    {
        if (++$nodes > $this->maxNodes) {
            return self::BUDGET_MARKER;
        }

        return match (true) {
            $value === null, is_bool($value), is_int($value), is_float($value) => $value,
            is_string($value)                                                  => $this->string($value),
            $value instanceof BackedEnum                                       => $value->value,
            $value instanceof UnitEnum                                         => $value->name,
            $value instanceof DateTimeInterface                                => $value->format(DateTimeInterface::ATOM),
            $value instanceof Closure                                          => '(closure)',
            is_array($value)                                                   => $depth >= $this->maxDepth ? sprintf('(array of %d items)', count($value)) : $this->items($value, $depth, $seen, $nodes),
            is_object($value)                                                  => $this->object($value, $depth, $seen, $nodes),
            is_resource($value)                                                => '(resource)',
            default                                                            => get_debug_type($value),
        };
    }

    /**
     * @param array<array-key, mixed> $items
     * @param array<int, true> $seen
     *
     * @return array<array-key, mixed>
     */
    private function items(array $items, int $depth, array &$seen, int &$nodes): array
    {
        $normalised = [];
        foreach (array_slice($items, 0, $this->maxItems, true) as $key => $item) {
            if ($nodes >= $this->maxNodes) {
                $normalised['…'] = self::BUDGET_MARKER;

                return $normalised;
            }
            $normalised[$key] = $this->value($item, $depth + 1, $seen, $nodes);
        }
        if (count($items) > $this->maxItems) {
            $normalised['…'] = sprintf('(%d more items)', count($items) - $this->maxItems);
        }

        return $normalised;
    }

    /**
     * @param array<int, true> $seen
     *
     * @return array<array-key, mixed>|string
     */
    private function object(object $object, int $depth, array &$seen, int &$nodes): array|string
    {
        $id = spl_object_id($object);
        if (isset($seen[$id])) {
            return sprintf('(ref: %s)', $object::class);
        }
        $reflection = new ReflectionObject($object);
        if ($depth >= $this->maxDepth || $this->isUninitializedLazy($reflection, $object)) {
            return sprintf($depth >= $this->maxDepth ? '(%s)' : '(lazy: %s)', $object::class);
        }
        $seen[$id] = true;

        $properties = [];
        do {
            foreach ($reflection->getProperties() as $property) {
                $name = $property->getName();
                if ($property->isStatic() || isset($properties[$name]) || $this->isVirtual($property) || !$property->isInitialized($object)) {
                    continue;
                }
                $properties[$name] = $this->rawValue($property, $object);
            }
            $reflection = $reflection->getParentClass();
        } while ($reflection !== false);

        return [self::CLASS_KEY => $object::class] + $this->items($properties, $depth, $seen, $nodes);
    }

    /**
     * PHP 8.4 lazy ghost / proxy not initialised yet (reading it would run its initialiser).
     *
     * @param ReflectionClass<object> $reflection
     */
    private function isUninitializedLazy(ReflectionClass $reflection, object $object): bool
    {
        return method_exists($reflection, 'isUninitializedLazyObject') && (bool) $reflection->isUninitializedLazyObject($object);
    }

    /**
     * PHP 8.4 hook-only property (no backing value; reading it would run the `get` hook).
     */
    private function isVirtual(ReflectionProperty $property): bool
    {
        return method_exists($property, 'isVirtual') && (bool) $property->isVirtual();
    }

    /**
     * Backing value, bypassing `get` hooks on PHP 8.4+ (no hooks exist before).
     */
    private function rawValue(ReflectionProperty $property, object $object): mixed
    {
        return method_exists($property, 'getRawValue') ? $property->getRawValue($object) : $property->getValue($object);
    }

    private function string(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            return '(binary data)';
        }
        if (mb_strlen($value) > $this->maxStringLength) {
            return mb_substr($value, 0, $this->maxStringLength) . '…';
        }

        return $value;
    }
}
