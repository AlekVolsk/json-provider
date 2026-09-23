<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\IdentifierRules;
use AV\JsonProvider\Schema\TableSchema;

/**
 * Rebuilds the provider's namespaced, version-tagged cache key from the
 * on-disk state — lets tests inspect or poison the exact entry the
 * provider currently resolves to.
 */
final class CacheKeys
{
    public static function current(string $dbDir, string $table): string
    {
        $real = realpath($dbDir);
        $meta = json_decode(
            (string)file_get_contents($dbDir . '/meta.json'),
            true,
        );
        \assert(\is_array($meta) && \is_array($meta[$table]));
        $lineCount = $meta[$table]['lineCount'] ?? 0;
        \assert(\is_int($lineCount));
        $dataFile = $dbDir . '/' . IdentifierRules::physicalName($table) . '/'
            . TableSchema::dataFileName($table);
        clearstatcache(true, $dataFile);
        $stat = file_exists($dataFile) ? stat($dataFile) : false;
        $fileState = $stat === false
            ? 'nofile'
            : $stat['size'] . '-' . $stat['ino'];

        return 'jdp:' . JsonDataProvider::CACHE_FORMAT_VERSION . ':'
            . substr(sha1($real === false ? $dbDir : $real), 0, 16) . ':'
            . $table . ':' . $lineCount . '-' . $fileState;
    }
}
