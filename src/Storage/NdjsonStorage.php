<?php

declare(strict_types=1);

namespace AV\JsonProvider\Storage;

use AV\JsonProvider\Exception\StorageException;

/**
 * Storage for NDJSON files — the single point of physical I/O for table and
 * index files.
 *
 * Layout: dbPath/<tableName>/<fileName>. Each table lives in its own
 * subdirectory together with all its index files.
 *
 * NDJSON: one record per line, 0-based line numbers. This allows reading a
 * specific record by line number without loading the entire file.
 *
 * Contract: the file must exist before any read. Creation is explicit via
 * createFile(). A missing file on read — exception.
 */
final class NdjsonStorage
{
    public function __construct(
        private readonly string $dbPath,
        private readonly TableLockManager | null $locks = null,
    ) {}

    /**
     * Reads the whole NDJSON file and returns all records.
     *
     * @return array<int,array<string,null|scalar>>
     */
    public function read(string $tableName, string $fileName): array
    {
        $path = $this->resolvePath($tableName, $fileName);
        $this->ensureFileExists($path);

        $result = [];
        $file = new \SplFileObject($path, 'r');
        $file->setFlags(
            \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE,
        );

        foreach ($file as $raw) {
            if (!\is_string($raw) || $raw === '') {
                continue;
            }

            $item = json_decode($raw, true);

            if (!\is_array($item)) {
                continue;
            }

            $row = [];

            foreach ($item as $key => $val) {
                if (\is_string($key) && (\is_scalar($val) || $val === null)) {
                    $row[$key] = $val;
                }
            }

            if ($row === []) {
                continue;
            }

            $result[] = $row;
        }

        return $result;
    }

    /**
     * Reads a single NDJSON line by 0-based line number.
     * Returns null if the line is missing or its content is invalid.
     *
     * @return null|array<string,null|scalar>
     */
    public function readLine(
        string $tableName,
        string $fileName,
        int $lineNumber,
    ): array | null {
        $path = $this->resolvePath($tableName, $fileName);
        $this->ensureFileExists($path);

        $file = new \SplFileObject($path, 'r');
        $file->seek($lineNumber);

        if ($file->eof()) {
            return null;
        }

        $raw = $file->current();

        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        $item = json_decode(trim($raw), true);

        if (!\is_array($item)) {
            return null;
        }

        $row = [];

        foreach ($item as $key => $val) {
            if (\is_string($key) && (\is_scalar($val) || $val === null)) {
                $row[$key] = $val;
            }
        }

        return $row === [] ? null : $row;
    }

    /**
     * Reads multiple NDJSON lines by an array of 0-based line numbers.
     * Returns records in the same order as the input numbers.
     * Lines not present in the file are skipped.
     *
     * @param array<int,int> $lineNumbers 0-based line numbers
     *
     * @return array<int,array<string,null|scalar>>
     */
    public function readLines(
        string $tableName,
        string $fileName,
        array $lineNumbers,
    ): array {
        if ($lineNumbers === []) {
            return [];
        }

        $path = $this->resolvePath($tableName, $fileName);
        $this->ensureFileExists($path);

        $index = [];

        foreach ($lineNumbers as $pos => $lineNumber) {
            $index[$lineNumber][] = $pos;
        }

        $result = array_fill(0, \count($lineNumbers), null);

        $file = new \SplFileObject($path, 'r');
        $file->setFlags(\SplFileObject::DROP_NEW_LINE);

        $current = 0;

        foreach ($file as $lineNum => $raw) {
            if (!isset($index[$lineNum])) {
                continue;
            }

            if (!\is_string($raw) || $raw === '') {
                continue;
            }

            $item = json_decode($raw, true);

            if (!\is_array($item)) {
                continue;
            }

            $row = [];

            foreach ($item as $key => $val) {
                if (\is_string($key) && (\is_scalar($val) || $val === null)) {
                    $row[$key] = $val;
                }
            }

            if ($row === []) {
                continue;
            }

            foreach ($index[$lineNum] as $pos) {
                $result[$pos] = $row;
            }

            $current++;

            if ($current === \count($index)) {
                break;
            }
        }

        return array_values(array_filter(
            $result,
            static fn (mixed $r): bool => $r !== null,
        ));
    }

    /**
     * Encodes records into their on-disk NDJSON byte form (one JSON object
     * per line, each line \n-terminated). The whole set is encoded before
     * any disk I/O: an unencodable record aborts with INVALID_RECORD while
     * the disk is still untouched. JSON_PRESERVE_ZERO_FRACTION keeps float
     * values (99.0) distinguishable from ints on re-read.
     *
     * @param array<int,array<string,null|scalar>> $records
     */
    public function encodeRecords(string $tableName, array $records): string
    {
        $buffer = '';

        foreach (array_values($records) as $record) {
            $line = json_encode($record, JSON_PRESERVE_ZERO_FRACTION);

            if ($line === false) {
                throw StorageException::invalidRecord(
                    $tableName,
                    json_last_error_msg(),
                );
            }

            $buffer .= $line . "\n";
        }

        return $buffer;
    }

    /**
     * Fully rewrites the NDJSON file: the record set is encoded into a
     * buffer first (any failure leaves the file untouched), then replaced
     * atomically via tmp+fsync+rename. Writer mutual exclusion comes from
     * the table EX lock held by the caller — concurrent readers see either
     * the old or the new complete file. Returns the new file size in bytes.
     *
     * @param array<int,array<string,null|scalar>> $records
     */
    public function write(
        string $tableName,
        string $fileName,
        array $records,
    ): int {
        $path = $this->resolvePath($tableName, $fileName);
        $this->ensureFileExists($path);

        \assert(
            $this->locks === null || $this->locks->isHeld($tableName, 'ex'),
            'NdjsonStorage::write requires the table EX lock',
        );

        $bytes = $this->encodeRecords($tableName, $records);

        return AtomicFileWriter::write($path, $bytes);
    }

    /**
     * Appends a single record to the end of an NDJSON file under an
     * exclusive file lock (which excludes concurrent appends; exclusion
     * against full rewrites comes from the caller's table EX lock), fsyncing
     * the result. A short write (ENOSPC, I/O error) is rolled back by
     * truncating to the pre-append size, so a torn line is never
     * acknowledged and the committed byteSize stays honest. Returns the file
     * size in bytes after the append.
     *
     * @param array<string,null|scalar> $record
     */
    public function append(
        string $tableName,
        string $fileName,
        array $record,
    ): int {
        $path = $this->resolvePath($tableName, $fileName);
        $this->ensureFileExists($path);

        $line = json_encode($record);

        if ($line === false) {
            throw StorageException::invalidRecord(
                $tableName,
                json_last_error_msg(),
            );
        }

        $handle = fopen($path, 'c+');

        if ($handle === false) {
            throw StorageException::fileNotWritable($path);
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw StorageException::lockFailed($path);
            }

            $before = fstat($handle);

            if ($before === false) {
                throw StorageException::fileNotWritable($path);
            }

            $preSize = max(0, $before['size']);

            if ($preSize > 0) {
                fseek($handle, -1, SEEK_END);

                if (fgetc($handle) !== "\n") {
                    throw StorageException::invalidRecord(
                        $tableName,
                        'data file tail is not newline-terminated; '
                            . 'repair the table first',
                    );
                }
            }

            fseek($handle, 0, SEEK_END);

            $bytes = $line . "\n";
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

            if (!$ok) {
                ftruncate($handle, $preSize);
                fflush($handle);
                fsync($handle);

                throw StorageException::fileNotWritable($path);
            }

            $size = $preSize + $length;
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return $size;
    }

    /**
     * Creates a new empty NDJSON file inside the table subdirectory (creates
     * the subdirectory if needed). Throws if the file already exists.
     */
    public function createFile(string $tableName, string $fileName): void
    {
        $this->ensureDbDir();
        $this->ensureTableDir($tableName);

        $path = $this->resolvePath($tableName, $fileName);

        if (file_exists($path)) {
            throw StorageException::tableFileExists($path);
        }

        AtomicFileWriter::write($path, '');
    }

    /**
     * Creates the file empty, or atomically empties it when it already
     * exists (empty temp + rename). For logical names freshly validated
     * against the schema under a held EX lock: createTable provisions the
     * data/index files as its last step with this, so leftover garbage of a
     * crashed or foreign file with the same name never leaks into a new
     * table.
     */
    public function createFileFresh(string $tableName, string $fileName): void
    {
        $this->ensureDbDir();
        $this->ensureTableDir($tableName);

        AtomicFileWriter::write(
            $this->resolvePath($tableName, $fileName),
            '',
        );
    }

    /**
     * Returns whether the specified NDJSON file exists.
     */
    public function exists(string $tableName, string $fileName): bool
    {
        return file_exists($this->resolvePath($tableName, $fileName));
    }

    /**
     * Returns the current on-disk size of the file in bytes, bypassing the
     * PHP stat cache. This is the "actual" side of the byteSize consistency
     * gate.
     */
    public function fileSizeBytes(string $tableName, string $fileName): int
    {
        $path = $this->resolvePath($tableName, $fileName);
        $this->ensureFileExists($path);

        clearstatcache(true, $path);
        $size = filesize($path);

        if ($size === false) {
            throw StorageException::fileNotReadable($path);
        }

        return $size;
    }

    /**
     * Repairs the tail of an NDJSON file after a crashed append, under an
     * exclusive file lock. Looks at the bytes after the last "\n":
     *
     *  - empty tail             -> 'none' (file already well-formed);
     *  - valid JSON object      -> append the missing "\n" — the record was
     *                              fully written, only the terminator was
     *                              lost ('newline-added', record saved);
     *  - anything else          -> truncate to the last "\n" — the record
     *                              never fully hit the disk and was never
     *                              acknowledged ('partial-truncated').
     *
     * Returns the action taken plus the resulting file size and line count.
     *
     * @return array{
     *     action: 'newline-added'|'none'|'partial-truncated',
     *     size: int,
     *     lines: int
     * }
     */
    public function repairTail(string $tableName, string $fileName): array
    {
        $path = $this->resolvePath($tableName, $fileName);
        $this->ensureFileExists($path);

        $handle = fopen($path, 'c+');

        if ($handle === false) {
            throw StorageException::fileNotWritable($path);
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw StorageException::lockFailed($path);
            }

            $contents = stream_get_contents($handle);

            if ($contents === false) {
                throw StorageException::fileNotReadable($path);
            }

            $lastNewline = strrpos($contents, "\n");
            $tailStart = $lastNewline === false ? 0 : max(0, $lastNewline + 1);
            $tail = substr($contents, $tailStart);
            $tailRecord = $tail === '' ? null : json_decode($tail, true);

            if ($tail === '') {
                $action = 'none';
                $size = \strlen($contents);
                $lines = substr_count($contents, "\n");
            } elseif (
                \is_array($tailRecord)
                && $tailRecord !== []
                && !array_is_list($tailRecord)
            ) {
                fseek($handle, 0, SEEK_END);
                $ok = fwrite($handle, "\n") === 1
                    && fflush($handle)
                    && fsync($handle);

                if (!$ok) {
                    throw StorageException::fileNotWritable($path);
                }

                $action = 'newline-added';
                $size = \strlen($contents) + 1;
                $lines = substr_count($contents, "\n") + 1;
            } else {
                $ok = ftruncate($handle, $tailStart)
                    && fflush($handle)
                    && fsync($handle);

                if (!$ok) {
                    throw StorageException::fileNotWritable($path);
                }

                $action = 'partial-truncated';
                $size = $tailStart;
                $lines = substr_count($contents, "\n");
            }

            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return ['action' => $action, 'size' => $size, 'lines' => $lines];
    }

    /**
     * Reads the raw byte contents of an NDJSON file (no parsing).
     * Throws if the file is missing or unreadable.
     *
     * Used by the backup service to copy file contents verbatim.
     */
    public function readRaw(string $tableName, string $fileName): string
    {
        $path = $this->resolvePath($tableName, $fileName);
        $this->ensureFileExists($path);

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw StorageException::fileNotReadable($path);
        }

        return $contents;
    }

    /**
     * Writes raw byte contents to an NDJSON file atomically
     * (tmp+fsync+rename). Used by the restore service to copy file contents
     * verbatim. Returns the byte size written.
     */
    public function writeRaw(
        string $tableName,
        string $fileName,
        string $contents,
    ): int {
        $path = $this->resolvePath($tableName, $fileName);
        $this->ensureFileExists($path);

        \assert(
            $this->locks === null || $this->locks->isHeld($tableName, 'ex'),
            'NdjsonStorage::writeRaw requires the table EX lock',
        );

        return AtomicFileWriter::write($path, $contents);
    }

    /**
     * Returns whether the table subdirectory exists.
     */
    public function tableDirExists(string $tableName): bool
    {
        $cleanTable = basename($tableName);

        if ($tableName === '' || $cleanTable !== $tableName) {
            throw StorageException::invalidFileName($tableName);
        }

        return is_dir($this->dbPath . '/' . $cleanTable);
    }

    /**
     * Lists regular files inside the table subdirectory (no recursion).
     * Returns base file names without the directory prefix. Empty list if the
     * subdirectory does not exist.
     *
     * @return array<int,string>
     */
    public function listFiles(string $tableName): array
    {
        $cleanTable = basename($tableName);

        if ($tableName === '' || $cleanTable !== $tableName) {
            throw StorageException::invalidFileName($tableName);
        }

        $dir = $this->dbPath . '/' . $cleanTable;

        if (!is_dir($dir)) {
            return [];
        }

        $entries = scandir($dir);

        if ($entries === false) {
            return [];
        }

        $files = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (is_file($dir . '/' . $entry)) {
                $files[] = $entry;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Deletes a file inside the table subdirectory. Throws on failure.
     * Missing file is a no-op (idempotent).
     */
    public function deleteFile(string $tableName, string $fileName): void
    {
        $path = $this->resolvePath($tableName, $fileName);

        if (!file_exists($path)) {
            return;
        }

        if (!unlink($path)) {
            throw StorageException::fileNotWritable($path);
        }
    }

    /**
     * Removes the entire table subdirectory including all of its files.
     * Idempotent — a missing subdirectory is a no-op.
     */
    public function deleteTable(string $tableName): void
    {
        foreach ($this->listFiles($tableName) as $fileName) {
            $this->deleteFile($tableName, $fileName);
        }

        $this->deleteTableDir($tableName);
    }

    /**
     * Removes the table subdirectory (must be empty). Idempotent.
     */
    public function deleteTableDir(string $tableName): void
    {
        $cleanTable = basename($tableName);

        if ($tableName === '' || $cleanTable !== $tableName) {
            throw StorageException::invalidFileName($tableName);
        }

        $dir = $this->dbPath . '/' . $cleanTable;

        if (!is_dir($dir)) {
            return;
        }

        if (!rmdir($dir)) {
            throw StorageException::fileNotWritable($dir);
        }
    }

    /**
     * Resolves the absolute path: dbPath/<tableName>/<fileName>.
     * Path-traversal guard: neither segment may contain separators.
     */
    private function resolvePath(string $tableName, string $fileName): string
    {
        $cleanTable = basename($tableName);
        $cleanFile = basename($fileName);

        if ($tableName === '' || $cleanTable !== $tableName) {
            throw StorageException::invalidFileName($tableName);
        }

        if ($fileName === '' || $cleanFile !== $fileName) {
            throw StorageException::invalidFileName($fileName);
        }

        return $this->dbPath . '/' . $cleanTable . '/' . $cleanFile;
    }

    private function ensureDbDir(): void
    {
        if (!is_dir($this->dbPath)) {
            throw StorageException::fileNotWritable($this->dbPath);
        }
    }

    /**
     * Creates the table subdirectory if missing.
     * No @-suppression: a failed mkdir raises an exception.
     */
    private function ensureTableDir(string $tableName): void
    {
        $dir = $this->dbPath . '/' . basename($tableName);

        if (is_dir($dir)) {
            return;
        }

        if (!mkdir($dir, 0755, false) && !is_dir($dir)) {
            throw StorageException::fileNotWritable($dir);
        }
    }

    private function ensureFileExists(string $path): void
    {
        if (!file_exists($path)) {
            throw StorageException::fileNotReadable($path);
        }
    }
}
