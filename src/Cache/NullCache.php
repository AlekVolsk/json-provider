<?php

declare(strict_types=1);

namespace AV\JsonProvider\Cache;

/**
 * No-op cache — stores nothing.
 * Used by default until a real adapter is provided.
 */
final class NullCache implements CacheInterface
{
    public function get(string $_key): array | null
    {
        return null;
    }

    /**
     * @param array<int,array<string,null|scalar>> $_records
     */
    public function set(string $_key, array $_records): void {}

    public function invalidate(string $_key): void {}

    public function flush(): void {}
}
