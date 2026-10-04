<?php

declare(strict_types=1);

namespace AV\JsonProvider\Services\Format;

/**
 * Whether the committed meta of a table still describes its data file —
 * and with it the indexes rebuilt for that file.
 */
enum FreshnessEnum: string
{
    /**
     * Size matches the committed byteSize and, in a generation-2
     * database, the inode matches the stamp: indexes are trusted.
     */
    case FRESH = 'fresh';

    /**
     * Size matches, but the table carries no stamp (an older engine
     * created or restored it): trusted the 1.0 way, by size alone.
     */
    case UNSTAMPED = 'unstamped';

    /**
     * Size matches, but the inode differs from the stamp: the file was
     * replaced after the last stamped commit — by an older engine or by a
     * rewrite interrupted before its commit. Indexes are not trusted.
     */
    case STALE = 'stale';

    /**
     * The committed byteSize (or the whole meta entry, or the file) does
     * not match: a crashed append, a foreign write, a missing entry.
     */
    case DRIFT = 'drift';

    /**
     * Whether queries and FK probes may rely on the indexes.
     */
    public function trusted(): bool
    {
        return $this === self::FRESH || $this === self::UNSTAMPED;
    }
}
