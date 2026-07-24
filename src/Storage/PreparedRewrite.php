<?php

declare(strict_types=1);

namespace AV\JsonProvider\Storage;

/**
 * Handle of a prepared (encoded, written to a temp sibling, fsynced) but
 * not yet committed NDJSON rewrite. Produced by
 * NdjsonStorage::prepareRewrite, consumed exactly once by
 * commitPrepared/abortPrepared.
 */
final class PreparedRewrite
{
    public function __construct(
        public readonly string $tableName,
        public readonly string $fileName,
        public readonly string $tmpPath,
        public readonly string $targetPath,
        public readonly int $byteSize,
    ) {}
}
