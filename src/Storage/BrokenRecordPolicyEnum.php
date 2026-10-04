<?php

declare(strict_types=1);

namespace AV\JsonProvider\Storage;

/**
 * What a write does with a data-file line that is not a record when it
 * rewrites the table (update, delete and their cascades, column DDL, the
 * self-healing rewrite of the consistency gate).
 *
 * Drop (default): the line is skipped on read, so the rewrite loses it
 * without a trace.
 *
 * Refuse: the write fails before touching the disk, naming the line; the
 * table keeps serving reads and appends, and every rewrite fails until the
 * line is fixed or removed by hand. A torn, unterminated last line left by
 * a crashed append is never acknowledged and is dropped under either
 * policy. Replacing the whole content on purpose (truncate, importRecords)
 * is not affected.
 */
enum BrokenRecordPolicyEnum
{
    case Drop;
    case Refuse;
}
