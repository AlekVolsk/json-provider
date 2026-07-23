<?php

declare(strict_types=1);

namespace AV\JsonProvider\Storage;

use AV\JsonProvider\Exception\StorageException;

/**
 * Atomic full-file replacement: the payload is written to a writer-unique
 * temp file (<path>.<pid>.<random>.tmp), flushed to hardware (fsync), then
 * rename()d over the target. A reader always sees either the complete old
 * file or the complete new one — never a truncated or partially written
 * state; a crash mid-write leaves the target untouched. Orphaned *.tmp
 * siblings of the target (crashed writers) are swept before each write.
 *
 * The unique temp name means two undisciplined concurrent writers can never
 * corrupt the target — the worst outcome is last-write-wins with a complete
 * file. Real writer mutual exclusion is the caller's job (table EX /
 * sidecar locks).
 *
 * The replaced file is created fresh with 0666 & ~umask under the writing
 * process's owner; a symlink at the target path is replaced by a regular
 * file, not written through.
 */
final class AtomicFileWriter
{
    /**
     * Writes $bytes to $path atomically and returns the byte size written.
     */
    public static function write(string $path, string $bytes): int
    {
        self::sweepOrphans($path);

        $tmp = $path . '.' . getmypid() . '.'
            . bin2hex(random_bytes(4)) . '.tmp';
        $handle = fopen($tmp, 'x');

        if ($handle === false) {
            throw StorageException::fileNotWritable($tmp);
        }

        $length = \strlen($bytes);
        $written = 0;
        $ok = true;

        while ($written < $length) {
            $chunk = fwrite(
                $handle,
                $written === 0 ? $bytes : substr($bytes, $written),
            );

            if ($chunk === false || $chunk === 0) {
                $ok = false;

                break;
            }

            $written += $chunk;
        }

        if ($ok) {
            $ok = fflush($handle) && fsync($handle);
        }

        fclose($handle);

        if (!$ok) {
            @unlink($tmp);

            throw StorageException::fileNotWritable($tmp);
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            throw StorageException::fileNotWritable($path);
        }

        return $length;
    }

    /**
     * Removes leftover temp files of previous crashed writers of this
     * target: every sibling named "<basename>.…tmp" (including the legacy
     * "<basename>.tmp" form).
     */
    private static function sweepOrphans(string $path): void
    {
        $dir = \dirname($path);
        $base = basename($path) . '.';
        $entries = @scandir($dir);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if (
                str_starts_with($entry, $base)
                && str_ends_with($entry, '.tmp')
            ) {
                @unlink($dir . '/' . $entry);
            }
        }
    }
}
