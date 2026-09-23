<?php

declare(strict_types=1);

namespace AV\JsonProvider\Services\Backup;

use AV\JsonProvider\Exception\JsonProviderIoException;
use AV\JsonProvider\Exception\JsonProviderServiceException;
use AV\JsonProvider\Exception\Locale\JsonProviderErrorEn;

/**
 * Temporary archive files for Backup and Restore.
 *
 * ext-phar keeps every archive it has opened in a per-process registry
 * keyed by path and never notices that the file at that path was replaced
 * or removed. In a long-lived process (queue worker, daemon, CLI script
 * running several backups) reopening a path therefore reads the archive
 * seen first, or fails outright. Backup and Restore never open a
 * user-visible path through phar: they work on files with one-off names,
 * and discard() removes a file together with its registry entry.
 *
 * Every temporary file is registered with track() when created and
 * unregistered by discard(). Whatever is still registered when the process
 * ends — after a fatal error such as memory exhaustion or a timeout — is
 * removed by a shutdown handler. Only SIGKILL leaves such files behind.
 *
 * @internal
 */
final class PharArchive
{
    private const string COPY_PREFIX = 'jp-restore-';

    /** @var array<string,true> */
    private static array $tracked = [];

    private static bool $shutdownHooked = false;

    /**
     * Registers a temporary file for removal at process end unless
     * discard() gets to it first.
     */
    public static function track(string $path): void
    {
        self::$tracked[$path] = true;

        if (!self::$shutdownHooked) {
            self::$shutdownHooked = true;
            register_shutdown_function(static function (): void {
                foreach (array_keys(self::$tracked) as $path) {
                    self::discard($path);
                }
            });
        }
    }

    /**
     * Creates a directory in the system temp directory accessible to the
     * owner only: files created inside are private regardless of umask.
     */
    public static function privateDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(8));

        if (!mkdir($dir, 0o700) || !chmod($dir, 0o700)) {
            throw new JsonProviderIoException(
                JsonProviderErrorEn::FileNotWritable,
                $dir,
            );
        }

        return $dir;
    }

    /**
     * Copies the archive to a one-off path in the system temp directory,
     * readable by the owner only. The copy keeps a .tar/.tar.gz extension
     * so ext-phar recognizes it whatever the source is named.
     */
    public static function privateCopy(string $source): string
    {
        $extension = str_ends_with($source, '.tar') ? '.tar' : '.tar.gz';
        $copy = sys_get_temp_dir() . '/' . self::COPY_PREFIX
            . bin2hex(random_bytes(8)) . $extension;

        $in = fopen($source, 'r');

        if ($in === false) {
            throw new JsonProviderServiceException(
                JsonProviderErrorEn::ArchiveNotReadable,
                $source,
            );
        }

        $out = fopen($copy, 'x');
        self::track($copy);

        if ($out === false) {
            fclose($in);

            throw new JsonProviderServiceException(
                JsonProviderErrorEn::ArchiveNotReadable,
                $source,
            );
        }

        chmod($copy, 0o600);
        $copied = stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        if ($copied === false) {
            unlink($copy);

            throw new JsonProviderServiceException(
                JsonProviderErrorEn::ArchiveNotReadable,
                $source,
            );
        }

        return $copy;
    }

    /**
     * Deletes the archive file, drops it from the phar registry and from
     * the shutdown cleanup list.
     */
    public static function discard(string $path): void
    {
        unset(self::$tracked[$path]);

        if (!file_exists($path)) {
            return;
        }

        try {
            \Phar::unlinkArchive($path);
        } catch (\Throwable) {
            unlink($path);
        }
    }
}
