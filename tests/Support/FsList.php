<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support;

/**
 * Directory listings for assertions: sorted names, with an unreadable or
 * missing directory listed as empty.
 */
final class FsList
{
    /**
     * Entry names of a directory without "." and "..".
     *
     * @return list<string>
     */
    public static function entries(string $dir): array
    {
        $names = is_dir($dir) ? scandir($dir) : false;

        if ($names === false) {
            return [];
        }

        $names = array_values(array_diff($names, ['.', '..']));
        sort($names);

        return $names;
    }

    /**
     * Paths matching a glob pattern.
     *
     * @return list<string>
     */
    public static function glob(string $pattern): array
    {
        $paths = glob($pattern);

        if ($paths === false) {
            return [];
        }

        sort($paths);

        return $paths;
    }
}
