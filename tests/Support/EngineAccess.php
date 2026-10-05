<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support;

use AV\JsonProvider\Engine\Context;
use AV\JsonProvider\Engine\Parts;
use AV\JsonProvider\JsonDataProvider;

/**
 * Reaches the engine parts behind a provider, for tests that look at or
 * adjust internal state.
 */
final class EngineAccess
{
    public static function context(JsonDataProvider $db): Context
    {
        $context = self::get($db, 'context');

        if (!$context instanceof Context) {
            throw new \LogicException('the provider holds no engine context');
        }

        return $context;
    }

    /**
     * The engine part of the provider built by Parts::$name(): store,
     * reader, writer, indexReader, schemaChanges, indexChanges or
     * maintenance.
     */
    public static function part(JsonDataProvider $db, string $name): object
    {
        $parts = self::get($db, 'parts');

        if (!$parts instanceof Parts) {
            throw new \LogicException('the provider holds no engine parts');
        }

        $part = self::call($parts, $name);

        if (!\is_object($part)) {
            throw new \LogicException('no engine part ' . $name);
        }

        return $part;
    }

    public static function get(object $object, string $property): mixed
    {
        return (new \ReflectionProperty($object, $property))
            ->getValue($object);
    }

    public static function set(
        object $object,
        string $property,
        mixed $value,
    ): void {
        (new \ReflectionProperty($object, $property))
            ->setValue($object, $value);
    }

    public static function call(
        object $object,
        string $method,
        mixed ...$args,
    ): mixed {
        return (new \ReflectionMethod($object, $method))
            ->invoke($object, ...$args);
    }
}
