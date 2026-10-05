<?php

declare(strict_types=1);

namespace AV\JsonProvider\Storage;

use AV\JsonProvider\Schema\IdentifierRules;

/**
 * Files derived from a table's data and index files, kept in .jdp/ of a
 * generation-2 database as table.<name>.*: they speed up index lookups and
 * are never a source of truth.
 *
 *  - .indexes.json — per index file, the inode, byte length, entry count
 *    and mark of its sorted head: a rebuild writes the whole index sorted,
 *    appends add an unsorted tail after it. Valid while the index file
 *    keeps the inode, is at least that long and the head ends with the
 *    same bytes.
 *  - .offsets — the byte offset of every line of the data file, after a
 *    header with the inode, size, line count and mark of the file it
 *    describes; valid only while all four match.
 *
 * Every file carries the identity of the file it describes, so one that a
 * crash, an older engine or a missed update left behind is recognized and
 * ignored — the reader falls back to the slow path, never to wrong data.
 * The mark — a checksum of the last MARK_BYTES bytes described — guards
 * against an inode number the file system handed out again to a file that
 * replaced the described one twice.
 * Nothing here is fsynced: a lost update is a stale file. Written only
 * under the table's EX lock, read under its SH lock.
 */
final class DerivedFiles
{
    private const string PREFIX = 'table.';
    private const string BOUNDARIES = '.indexes.json';
    private const string OFFSETS = '.offsets';
    private const int HEADER = 32;
    private const int ENTRY = 8;
    private const int MARK_BYTES = 64;
    private const int PACK_CHUNK = 8192;

    private bool $enabled = false;

    public function __construct(private readonly string $dbPath)
    {
    }

    /**
     * Turns maintenance on: only a generation-2 database keeps derived
     * files.
     */
    public function enable(): void
    {
        $this->enabled = true;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Creates the directory the derived files live in. Called by the
     * migration to generation 2 under the database EX lock, before any
     * table is rebuilt; a directory without a manifest still reads as
     * generation 1.
     */
    public function prepare(): void
    {
        $dir = $this->dbPath . '/' . StorageManifest::DIR;

        if (!is_dir($dir)) {
            mkdir($dir, 0755);
        }
    }

    /**
     * Recomputes the line offsets of the data file from its bytes.
     */
    public function rebuildLineOffsets(string $table, string $dataPath): void
    {
        if (!$this->enabled) {
            return;
        }

        $bytes = file_get_contents($dataPath);
        $stat = self::stat($dataPath);

        if ($bytes === false || $stat === null) {
            return;
        }

        $offsets = [];
        $start = 0;
        $length = \strlen($bytes);

        while ($start < $length) {
            $end = strpos($bytes, "\n", $start);

            if ($end === false) {
                break;
            }

            $offsets[] = $start;
            $start = $end + 1;
        }

        $body = pack(
            'JJJJ',
            $stat['ino'],
            $stat['size'],
            \count($offsets),
            self::markOf($bytes, $length),
        );

        foreach (array_chunk($offsets, self::PACK_CHUNK) as $chunk) {
            $body .= pack('J*', ...$chunk);
        }

        $this->replace($table, self::OFFSETS, $body);
    }

    /**
     * Records the offset of a line just appended to the data file, when
     * the offsets describe the file as it was before the append — its
     * inode, line count and the mark of its content up to the recorded
     * size; otherwise leaves them stale.
     */
    public function appendLineOffset(
        string $table,
        string $dataPath,
        int $lineCount,
        int $byteSize,
    ): void {
        if (!$this->enabled) {
            return;
        }

        $path = $this->path($table, self::OFFSETS);
        $stat = self::stat($dataPath);
        $handle = $stat === null || !is_file($path)
            ? false
            : fopen($path, 'r+');

        if ($handle === false) {
            return;
        }

        try {
            $header = self::header($handle);

            if (
                $header === null
                || $header['ino'] !== $stat['ino']
                || $header['count'] !== $lineCount - 1
                || $header['size'] >= $byteSize
                || self::length($handle) !== self::HEADER
                    + $header['count'] * self::ENTRY
            ) {
                return;
            }

            $mark = self::fileMark($dataPath, $byteSize);

            if (
                $mark === null
                || self::fileMark($dataPath, $header['size'])
                    !== $header['mark']
            ) {
                return;
            }

            fseek($handle, 0, SEEK_END);
            fwrite($handle, pack('J', $header['size']));
            fseek($handle, 0);
            fwrite(
                $handle,
                pack('JJJJ', $stat['ino'], $byteSize, $lineCount, $mark),
            );
        } finally {
            fclose($handle);
        }
    }

    /**
     * The byte offsets of the given data lines, or null when the offsets
     * do not describe the data file the caller holds open (its inode, size
     * and mark, and the line count) or a line is out of range. The open
     * file, not the one at the path, is what the caller reads: the path
     * may already lead to a newer file.
     *
     * @param resource       $data
     * @param array<int,int> $lines
     *
     * @return null|array<int,int> line number => byte offset
     */
    public function lineOffsets(
        string $table,
        mixed $data,
        int $lineCount,
        array $lines,
    ): array | null {
        if (!$this->enabled) {
            return null;
        }

        $stat = fstat($data);

        if ($stat === false) {
            return null;
        }

        return $this->readOffsets(
            $table,
            ['ino' => $stat['ino'], 'size' => $stat['size']],
            self::handleMark($data, $stat['size']),
            $lineCount,
            $lines,
        );
    }

    /**
     * The sorted head of an index file — its byte length and entry count —
     * or null when nothing valid is recorded for the file as it is now.
     *
     * @return null|array{bytes:int, count:int}
     */
    public function indexBoundary(
        string $table,
        string $indexFile,
        string $indexPath,
    ): array | null {
        return $this->enabled
            ? $this->readBoundary($table, $indexFile, $indexPath)
            : null;
    }

    /**
     * Records that the first $bytes bytes ($count entries) of the index
     * file as it is now are sorted by key.
     */
    public function setIndexBoundary(
        string $table,
        string $indexFile,
        string $indexPath,
        int $bytes,
        int $count,
    ): void {
        if (!$this->enabled) {
            return;
        }

        $stat = self::stat($indexPath);
        $mark = self::fileMark($indexPath, $bytes);

        if ($stat === null || $mark === null) {
            return;
        }

        $boundaries = $this->boundaries($table);
        $boundaries[$indexFile] = [
            'ino'   => $stat['ino'],
            'bytes' => $bytes,
            'count' => $count,
            'mark'  => $mark,
        ];
        ksort($boundaries);

        $this->replace(
            $table,
            self::BOUNDARIES,
            json_encode($boundaries, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Whether the table's derived files describe its data and index files
     * as they are now: offsets of every data line and a sorted head for
     * every index file. Reads the disk whether or not maintenance is on.
     *
     * @param array<string,string> $indexFiles index file name => path
     */
    public function tableCurrent(
        string $table,
        string $dataPath,
        int $lineCount,
        array $indexFiles,
    ): bool {
        $stat = self::stat($dataPath);
        $mark = $stat === null
            ? null
            : self::fileMark($dataPath, $stat['size']);

        if ($this->readOffsets($table, $stat, $mark, $lineCount, []) === null) {
            return false;
        }

        foreach ($indexFiles as $file => $path) {
            if ($this->readBoundary($table, $file, $path) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Removes the derived files of a dropped table.
     */
    public function dropTable(string $table): void
    {
        foreach ([self::BOUNDARIES, self::OFFSETS] as $file) {
            if (is_file($this->path($table, $file))) {
                unlink($this->path($table, $file));
            }
        }
    }

    /**
     * Moves the derived files with a renamed table: the data and index
     * files keep their inodes, so the files stay valid.
     */
    public function renameTable(string $from, string $to): void
    {
        $this->dropTable($to);

        foreach ([self::BOUNDARIES, self::OFFSETS] as $file) {
            if (is_file($this->path($from, $file))) {
                rename($this->path($from, $file), $this->path($to, $file));
            }
        }
    }

    /**
     * The offsets of $lines when the offsets file describes the data file
     * with $stat (inode and size) and $mark, and $lineCount lines.
     *
     * @param null|array{ino:int, size:int} $stat
     * @param array<int,int>                $lines
     *
     * @return null|array<int,int>
     */
    private function readOffsets(
        string $table,
        array | null $stat,
        int | null $mark,
        int $lineCount,
        array $lines,
    ): array | null {
        $path = $this->path($table, self::OFFSETS);
        $handle = $stat === null || $mark === null || !is_file($path)
            ? false
            : fopen($path, 'r');

        if ($handle === false) {
            return null;
        }

        try {
            $header = self::header($handle);

            if (
                $header === null
                || $header['ino'] !== $stat['ino']
                || $header['size'] !== $stat['size']
                || $header['count'] !== $lineCount
                || self::length($handle) !== self::HEADER
                    + $lineCount * self::ENTRY
                || $header['mark'] !== $mark
            ) {
                return null;
            }

            $offsets = [];

            foreach ($lines as $line) {
                if ($line < 0 || $line >= $lineCount) {
                    return null;
                }

                fseek($handle, self::HEADER + $line * self::ENTRY);
                $packed = fread($handle, self::ENTRY);
                $offset = $packed === false || \strlen($packed) !== self::ENTRY
                    ? false
                    : unpack('J', $packed);

                if (!\is_array($offset) || !\is_int($offset[1] ?? null)) {
                    return null;
                }

                $offsets[$line] = $offset[1];
            }

            return $offsets;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return null|array{bytes:int, count:int}
     */
    private function readBoundary(
        string $table,
        string $indexFile,
        string $indexPath,
    ): array | null {
        $entry = $this->boundaries($table)[$indexFile] ?? null;
        $stat = self::stat($indexPath);

        if (
            $entry === null
            || $stat === null
            || $entry['ino'] !== $stat['ino']
            || $entry['bytes'] > $stat['size']
            || $entry['mark'] !== self::fileMark($indexPath, $entry['bytes'])
        ) {
            return null;
        }

        return ['bytes' => $entry['bytes'], 'count' => $entry['count']];
    }

    /**
     * @return array<string,array{ino:int, bytes:int, count:int, mark:int}>
     */
    private function boundaries(string $table): array
    {
        $path = $this->path($table, self::BOUNDARIES);
        $raw = is_file($path) ? file_get_contents($path) : false;
        $data = $raw === false ? null : json_decode($raw, true);

        if (!\is_array($data)) {
            return [];
        }

        $result = [];

        foreach ($data as $file => $entry) {
            if (
                \is_string($file)
                && \is_array($entry)
                && \is_int($entry['ino'] ?? null)
                && \is_int($entry['bytes'] ?? null)
                && \is_int($entry['count'] ?? null)
                && \is_int($entry['mark'] ?? null)
            ) {
                $result[$file] = [
                    'ino'   => $entry['ino'],
                    'bytes' => $entry['bytes'],
                    'count' => $entry['count'],
                    'mark'  => $entry['mark'],
                ];
            }
        }

        return $result;
    }

    private function replace(string $table, string $file, string $bytes): void
    {
        if (!is_dir($this->dbPath . '/' . StorageManifest::DIR)) {
            return;
        }

        $path = $this->path($table, $file);
        $tmp = $path . '.tmp';

        if (file_put_contents($tmp, $bytes) === \strlen($bytes)) {
            rename($tmp, $path);
        }
    }

    private function path(string $table, string $file): string
    {
        return $this->dbPath . '/' . StorageManifest::DIR . '/' . self::PREFIX
            . IdentifierRules::physicalName($table) . $file;
    }

    /**
     * @param resource $handle
     */
    private static function length($handle): int
    {
        $stat = fstat($handle);

        return $stat === false ? -1 : $stat['size'];
    }

    /**
     * @param resource $handle
     *
     * @return null|array{ino:int, size:int, count:int, mark:int}
     */
    private static function header($handle): array | null
    {
        $packed = fread($handle, self::HEADER);

        if ($packed === false || \strlen($packed) !== self::HEADER) {
            return null;
        }

        $header = unpack('Jino/Jsize/Jcount/Jmark', $packed);

        if (
            !\is_array($header)
            || !\is_int($header['ino'] ?? null)
            || !\is_int($header['size'] ?? null)
            || !\is_int($header['count'] ?? null)
            || !\is_int($header['mark'] ?? null)
        ) {
            return null;
        }

        return [
            'ino'   => $header['ino'],
            'size'  => $header['size'],
            'count' => $header['count'],
            'mark'  => $header['mark'],
        ];
    }

    /**
     * The mark of the first $end bytes of a file: a checksum of their last
     * MARK_BYTES bytes.
     */
    private static function fileMark(string $path, int $end): int | null
    {
        $handle = is_file($path) ? fopen($path, 'r') : false;

        if ($handle === false) {
            return null;
        }

        $from = max(0, $end - self::MARK_BYTES);
        $length = $end - $from;

        try {
            fseek($handle, $from);
            $bytes = $length < 1 ? '' : fread($handle, $length);
        } finally {
            fclose($handle);
        }

        return $bytes === false || \strlen($bytes) !== max(0, $length)
            ? null
            : crc32($bytes);
    }

    /**
     * fileMark() of a file the caller holds open.
     *
     * @param resource $handle
     */
    private static function handleMark(mixed $handle, int $end): int | null
    {
        $from = max(0, $end - self::MARK_BYTES);
        $length = $end - $from;
        fseek($handle, $from);
        $bytes = $length < 1 ? '' : fread($handle, $length);

        return $bytes === false || \strlen($bytes) !== max(0, $length)
            ? null
            : crc32($bytes);
    }

    private static function markOf(string $bytes, int $end): int
    {
        $from = max(0, $end - self::MARK_BYTES);

        return crc32(substr($bytes, $from, $end - $from));
    }

    /**
     * @return null|array{ino:int, size:int}
     */
    private static function stat(string $path): array | null
    {
        clearstatcache(true, $path);
        $stat = is_file($path) ? stat($path) : false;

        return $stat === false
            ? null
            : ['ino' => $stat['ino'], 'size' => $stat['size']];
    }
}
