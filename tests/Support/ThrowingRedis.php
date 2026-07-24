<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support;

/**
 * A \Redis whose every data operation throws — emulates a down or
 * misbehaving backend for the degradation-policy tests.
 */
final class ThrowingRedis extends \Redis
{
    public function __construct()
    {
        parent::__construct();
    }

    public function get(string $key): mixed
    {
        throw new \RedisException('backend down');
    }

    /**
     * @param null|mixed[] $options
     */
    public function set(
        string $key,
        mixed $value,
        mixed $options = null,
    ): bool | \Redis | string {
        throw new \RedisException('backend down');
    }

    public function setex(
        string $key,
        int $expire,
        mixed $value,
    ): bool | \Redis {
        throw new \RedisException('backend down');
    }

    /**
     * @param string|string[] $key
     */
    public function del(
        array | string $key,
        string ...$other_keys,
    ): false | int | \Redis {
        throw new \RedisException('backend down');
    }

    /**
     * @param string|string[] $key
     */
    public function unlink(
        array | string $key,
        string ...$other_keys,
    ): false | int | \Redis {
        throw new \RedisException('backend down');
    }

    /**
     * @return false|string[]
     */
    public function scan(
        int | string | null &$iterator,
        string | null $pattern = null,
        int $count = 0,
        string | null $type = null,
    ): array | false {
        throw new \RedisException('backend down');
    }
}
