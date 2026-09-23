<?php

declare(strict_types=1);

namespace AV\JsonProvider\Storage;

use AV\JsonProvider\Exception\JsonProviderIoException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;

/**
 * Atomic full-file replacement: the payload is written to a writer-unique
 * temp file (<path>.<pid>.<random>.tmp), flushed to hardware (fsync), then
 * rename()d over the target, and the directory is fsynced so the rename
 * itself survives a power loss. A reader always sees either the complete old
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
        $tmp = self::prepare($path, $bytes);
        self::commit($tmp, $path);

        return \strlen($bytes);
    }

    /**
     * The PREPARE half of the atomic replacement: writes $bytes to a
     * writer-unique temp sibling of $path, flushed to hardware, WITHOUT
     * renaming it over the target. Returns the temp path for a later
     * commit()/abort(). Lets a multi-file writer surface every encode and
     * I/O failure while all targets are still untouched, then flip the
     * files with nothing but cheap renames.
     */
    public static function prepare(string $path, string $bytes): string
    {
        self::sweepOrphans($path);

        $tmp = $path . '.' . getmypid() . '.'
            . bin2hex(random_bytes(4)) . '.tmp';
        $handle = fopen($tmp, 'x');

        if ($handle === false) {
            throw new JsonProviderIoException(
                JsonProviderErrorEn::FileNotWritable,
                $tmp,
            );
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
            self::removeIfPresent($tmp);

            throw new JsonProviderIoException(
                JsonProviderErrorEn::FileNotWritable,
                $tmp,
            );
        }

        return $tmp;
    }

    /**
     * The COMMIT half: renames a prepared temp file over its target and
     * fsyncs the directory, making the new directory entry durable.
     */
    public static function commit(string $tmp, string $path): void
    {
        if (!rename($tmp, $path)) {
            self::removeIfPresent($tmp);

            throw new JsonProviderIoException(
                JsonProviderErrorEn::FileNotWritable,
                $path,
            );
        }

        self::syncDirectory(\dirname($path));
    }

    /**
     * Best-effort directory fsync so a metadata operation (rename) is
     * durable before the caller proceeds. On filesystems or PHP builds
     * where a directory cannot be opened or synced the call degrades to a
     * no-op — the rename itself is still atomic, only its durability window
     * widens.
     */
    public static function syncDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $handle = fopen($dir, 'r');

        if ($handle === false) {
            return;
        }

        fsync($handle);
        fclose($handle);
    }

    /**
     * Drops a prepared temp file without touching the target. Safe to call
     * after a failed prepare of a sibling — a missing temp file is not an
     * error.
     */
    public static function abort(string $tmp): void
    {
        self::removeIfPresent($tmp);
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

        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if (
                str_starts_with($entry, $base)
                && str_ends_with($entry, '.tmp')
            ) {
                self::removeIfPresent($dir . '/' . $entry);
            }
        }
    }

    /**
     * Temp files are removed on failure paths where the file may already be
     * gone (never created, or swept by a concurrent writer): absence is the
     * desired outcome, not an error.
     */
    private static function removeIfPresent(string $file): void
    {
        if (file_exists($file)) {
            unlink($file);
        }
    }
}
