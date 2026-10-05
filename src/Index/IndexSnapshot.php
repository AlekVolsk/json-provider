<?php

declare(strict_types=1);

namespace AV\JsonProvider\Index;

/**
 * What an index read takes from a table while it holds the table's SH
 * lock, to read after releasing it: the line count the trusted indexes
 * describe, the data file open at its committed size, and the index — its
 * sorted head and tail open for searching in place, or its entries read
 * whole. A writer replaces a file by rename, which leaves the one open
 * here as it was, and appends in place only past the sizes taken here, so
 * the read sees the table as it was while the lock was held.
 */
final class IndexSnapshot
{
    /**
     * @param resource                                    $data
     * @param null|array{IndexFileRegion, IndexEntryList} $sorted
     * @param array<int,array{key:string,line:int}>       $entries the whole
     *                                                             index when
     *                                                             $sorted is
     *                                                             null
     */
    public function __construct(
        public readonly int $lineCount,
        public readonly mixed $data,
        public readonly int $dataSize,
        public readonly array | null $sorted,
        public readonly array $entries,
    ) {
    }

    public function __destruct()
    {
        if (\is_resource($this->data)) {
            fclose($this->data);
        }
    }
}
