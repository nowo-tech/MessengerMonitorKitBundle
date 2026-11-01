<?php

declare(strict_types=1);

namespace Nowo\MessengerMonitorKitBundle\Payload;

use BackedEnum;
use Closure;
use DateTimeInterface;
use ReflectionObject;
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
use function spl_object_id;
use function sprintf;

/**
 * Turns a message object into a bounded array of scalars for the payload view: public, protected and private
 * properties (parents included), at most `payload.max_depth` levels and `payload.max_items` items per level, strings
 * cut at `payload.max_string_length`, binary strings, closures and resources replaced by a marker, recursion broken.
 * Nothing is executed: no getter, `__toString()` or serializer runs on the message. Stateless.
 */
final readonly class PayloadNormalizer
{
    /** Key holding the class of a normalised object. */
    public const CLASS_KEY = '@class';

    /**
     * @param int $maxDepth nesting levels kept
     * @param int $maxItems items kept per array / object
     * @param int $maxStringLength characters kept per string
     */
    public function __construct(
        private int $maxDepth = 4,
        private int $maxItems = 50,
        private int $maxStringLength = 500,
    ) {
    }

    /**
     * Normalised array of an object (or of any value, wrapped under `value`).
     *
     * @return array<array-key, mixed>
     */
    public function normalize(mixed $value): array
    {
        $normalised = $this->value($value, 0, []);

        return is_array($normalised) ? $normalised : ['value' => $normalised];
    }

    /**
     * @param array<int, true> $seen ids of the objects on the current path
     */
    private function value(mixed $value, int $depth, array $seen): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value), is_float($value) => $value,
            is_string($value)                                                  => $this->string($value),
            $value instanceof BackedEnum                                       => $value->value,
            $value instanceof UnitEnum                                         => $value->name,
            $value instanceof DateTimeInterface                                => $value->format(DateTimeInterface::ATOM),
            $value instanceof Closure                                          => '(closure)',
            is_array($value)                                                   => $depth >= $this->maxDepth ? sprintf('(array of %d items)', count($value)) : $this->items($value, $depth, $seen),
            is_object($value)                                                  => $this->object($value, $depth, $seen),
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
    private function items(array $items, int $depth, array $seen): array
    {
        $normalised = [];
        foreach (array_slice($items, 0, $this->maxItems, true) as $key => $item) {
            $normalised[$key] = $this->value($item, $depth + 1, $seen);
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
    private function object(object $object, int $depth, array $seen): array|string
    {
        $id = spl_object_id($object);
        if (isset($seen[$id])) {
            return sprintf('(recursion: %s)', $object::class);
        }
        if ($depth >= $this->maxDepth) {
            return sprintf('(%s)', $object::class);
        }
        $seen[$id] = true;

        $properties = [];
        $reflection = new ReflectionObject($object);
        do {
            foreach ($reflection->getProperties() as $property) {
                $name = $property->getName();
                if ($property->isStatic() || isset($properties[$name]) || !$property->isInitialized($object)) {
                    continue;
                }
                $properties[$name] = $property->getValue($object);
            }
            $reflection = $reflection->getParentClass();
        } while ($reflection !== false);

        return [self::CLASS_KEY => $object::class] + $this->items($properties, $depth, $seen);
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
