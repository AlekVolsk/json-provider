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
     * Reads the whole NDJSON file, separating parseable records from
     * broken lines. Every non-empty line that read() would silently skip —
     * invalid JSON, a JSON scalar, or a row that sanitizes to nothing —
     * is reported in 'broken' with its 0-based PHYSICAL line number and
     * the raw text, while 'records' holds exactly what read() returns.
     * Empty lines are neither records nor broken (an in-flight append may
     * legitimately end mid-line, and read() skips them too).
     *
     * The integrity validator reports broken lines; optimize/repair
     * rewrites refuse to run over a file that has any, because a
     * read()+write() roundtrip would silently drop them.
     *
     * @return array{
     *     records: array<int,array<string,null|scalar>>,
     *     broken: array<int,array{line:int,raw:string}>
     * }
     */
    public function readRawLines(string $tableName, string $fileName): array
    {
        $path = $this->resolvePath($tableName, $fileName);
        $this->ensureFileExists($path);

        $records = [];
        $broken = [];
        $file = new \SplFileObject($path, 'r');
        $file->setFlags(\SplFileObject::DROP_NEW_LINE);

        foreach ($file as $lineNumber => $raw) {
            if (!\is_string($raw) || $raw === '') {
                continue;
            }

            $item = json_decode($raw, true);

            if (!\is_array($item)) {
                $broken[] = ['line' => $lineNumber, 'raw' => $raw];

                continue;
            }

            $row = [];

            foreach ($item as $key => $val) {
                if (\is_string($key) && (\is_scalar($val) || $val === null)) {
                    $row[$key] = $val;
                }
            }

            if ($row === []) {
                $broken[] = ['line' => $lineNumber, 'raw' => $raw];

                continue;
            }

            $records[] = $row;
        }

        return ['records' => $records, 'broken' => $broken];
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
     * Reads and decodes the last non-empty line of an NDJSON file in O(1)
     * relative to the file size: seeks to the end and scans backward in
     * fixed-size chunks until the newline preceding the last non-empty
     * line (or the file start) is found. Returns null for an empty file
     * or when the tail line does not decode into a usable record.
     *
     * @return null|array<string,null|scalar>
     */
    public function readLastLine(
        string $tableName,
        string $fileName,
    ): array | null {
        $path = $this->resolvePath($tableName, $fileName);
        $this->ensureFileExists($path);

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw StorageException::fileNotReadable($path);
        }

        try {
            fseek($handle, 0, SEEK_END);
            $pos = ftell($handle);

            if ($pos === false || $pos === 0) {
                return null;
            }

            $buffer = '';

            while ($pos > 0) {
                $readFrom = max(0, $pos - 8192);
                $length = $pos - $readFrom;

                if ($length < 1) {
                    break;
                }

                fseek($handle, $readFrom);
                $piece = fread($handle, $length);

                if ($piece === false) {
                    throw StorageException::fileNotReadable($path);
                }

                $buffer = $piece . $buffer;
                $pos = $readFrom;

                $trimmed = rtrim($buffer, "\n");

                if ($trimmed === '') {
                    continue;
                }

                if (strrpos($trimmed, "\n") !== false) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        $trimmed = rtrim($buffer, "\n");

        if ($trimmed === '') {
            return null;
        }

        $newline = strrpos($trimmed, "\n");
        $line = $newline === false
            ? $trimmed
            : substr($trimmed, $newline + 1);

        $item = json_decode($line, true);

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
        $this->requireTableExLock($tableName, 'write');

        $bytes = $this->encodeRecords($tableName, $records);

        return AtomicFileWriter::write($path, $bytes);
    }

    /**
     * PREPARE half of a two-phase full rewrite: encodes the record set and
     * writes it to a fsynced temp sibling of the data file, leaving the
     * target untouched. The multi-table FK commit prepares every affected
     * table first, so any encode or I/O failure aborts the whole write set
     * with every data file still in its old state; the commit phase is
     * then nothing but renames. Requires the same table EX lock as
     * write().
     *
     * @param array<int,array<string,null|scalar>> $records
     */
    public function prepareRewrite(
        string $tableName,
        string $fileName,
        array $records,
    ): PreparedRewrite {
        $path = $this->resolvePath($tableName, $fileName);
        $this->ensureFileExists($path);
        $this->requireTableExLock($tableName, 'prepareRewrite');

        $bytes = $this->encodeRecords($tableName, $records);

        return new PreparedRewrite(
            tableName: $tableName,
            fileName: $fileName,
            tmpPath: AtomicFileWriter::prepare($path, $bytes),
            targetPath: $path,
            byteSize: \strlen($bytes),
        );
    }

    /**
     * COMMIT half: renames the prepared temp file over the data file.
     */
    public function commitPrepared(PreparedRewrite $prepared): void
    {
        AtomicFileWriter::commit($prepared->tmpPath, $prepared->targetPath);
    }

    /**
     * Drops a prepared temp file, leaving the data file untouched.
     */
    public function abortPrepared(PreparedRewrite $prepared): void
    {
        AtomicFileWriter::abort($prepared->tmpPath);
    }

    /**
     * Appends a single record to the end of an NDJSON file under an
     * exclusive file lock (which excludes concurrent appends; exclusion
     * against full rewrites comes from the caller's table EX lock), fsyncing
     * the result. A short write (ENOSPC, I/O error) is rolled back by
     * truncating to the pre-append size, so a torn line is never
     * acknowledged and the committed byteSize stays honest. Returns the file
     * size in bytes after the append. JSON_PRESERVE_ZERO_FRACTION keeps
     * float values (99.0) distinguishable from ints on re-read, matching
     * the full-rewrite encoding.
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

        $line = json_encode($record, JSON_PRESERVE_ZERO_FRACTION);

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
     * Size and inode of the file in one fresh stat. Every full rewrite
     * goes through tmp+rename and lands on a NEW inode, so the pair
     * identifies a committed file state far more precisely than the size
     * alone — the cache version tag builds on that.
     *
     * @return array{size:int,ino:int}
     */
    public function fileStat(string $tableName, string $fileName): array
    {
        $path = $this->resolvePath($tableName, $fileName);
        $this->ensureFileExists($path);

        clearstatcache(true, $path);
        $stat = @stat($path);

        if ($stat === false) {
            throw StorageException::fileNotReadable($path);
        }

        return ['size' => $stat['size'], 'ino' => $stat['ino']];
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
        $this->requireTableExLock($tableName, 'writeRaw');

        return AtomicFileWriter::write($path, $contents);
    }

    /**
     * Returns whether the table subdirectory exists.
     */
    public function tableDirExists(string $tableName): bool
    {
        self::assertSegment($tableName);

        return is_dir($this->dbPath . '/' . $tableName);
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
        self::assertSegment($tableName);

        $dir = $this->dbPath . '/' . $tableName;

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
     * Renames the table subdirectory dbPath/<from> to dbPath/<to> and
     * fsyncs the database directory so the rename survives a crash.
     * Idempotent for crash recovery: when the source is gone and the
     * target exists, the rename already happened and the call is a no-op.
     * A target that exists alongside the source is never overwritten.
     */
    public function renameTableDir(string $from, string $to): void
    {
        self::assertSegment($from);
        self::assertSegment($to);

        $src = $this->dbPath . '/' . $from;
        $dst = $this->dbPath . '/' . $to;

        if (!is_dir($src)) {
            if (is_dir($dst)) {
                return;
            }

            throw StorageException::fileNotReadable($src);
        }

        if (is_dir($dst)) {
            throw StorageException::tableFileExists($dst);
        }

        if (!rename($src, $dst)) {
            throw StorageException::fileNotWritable($dst);
        }

        self::fsyncDir($this->dbPath);
    }

    /**
     * Renames a file inside the table subdirectory and fsyncs that
     * subdirectory. Same idempotence contract as renameTableDir: source
     * gone + target present is a completed rename, an existing target is
     * never overwritten.
     */
    public function renameFile(
        string $tableName,
        string $fromFile,
        string $toFile,
    ): void {
        $src = $this->resolvePath($tableName, $fromFile);
        $dst = $this->resolvePath($tableName, $toFile);

        if (!file_exists($src)) {
            if (file_exists($dst)) {
                return;
            }

            throw StorageException::fileNotReadable($src);
        }

        if (file_exists($dst)) {
            throw StorageException::tableFileExists($dst);
        }

        if (!rename($src, $dst)) {
            throw StorageException::fileNotWritable($dst);
        }

        self::fsyncDir($this->dbPath . '/' . $tableName);
    }

    /**
     * Removes the table subdirectory (must be empty). Idempotent.
     */
    public function deleteTableDir(string $tableName): void
    {
        self::assertSegment($tableName);

        $dir = $this->dbPath . '/' . $tableName;

        if (!is_dir($dir)) {
            return;
        }

        if (!rmdir($dir)) {
            throw StorageException::fileNotWritable($dir);
        }
    }

    /**
     * Best-effort directory fsync so a metadata operation (rename) is
     * durable before the caller proceeds. On filesystems or PHP builds
     * where a directory cannot be opened or synced the call degrades
     * silently — the rename itself is still atomic, only its durability
     * window widens.
     */
    private static function fsyncDir(string $dir): void
    {
        $handle = @fopen($dir, 'r');

        if ($handle === false) {
            return;
        }

        @fsync($handle);
        fclose($handle);
    }

    /**
     * Resolves the absolute path: dbPath/<tableName>/<fileName>.
     * Path-traversal guard: neither segment may contain separators.
     */
    private function resolvePath(string $tableName, string $fileName): string
    {
        self::assertSegment($tableName);
        self::assertSegment($fileName);

        return $this->dbPath . '/' . $tableName . '/' . $fileName;
    }

    /**
     * Path-traversal guard for a single path segment: rejects the empty
     * string, the dot directories and any name containing a slash or a
     * backslash. basename() alone is not enough — basename('.') and
     * basename('..') return their input unchanged, and on POSIX a
     * backslash is a regular character, so "..\x5c.." would slip through
     * a pure basename comparison.
     */
    private static function assertSegment(string $name): void
    {
        if (
            $name === ''
            || $name === '.'
            || $name === '..'
            || strpbrk($name, '/\\') !== false
            || basename($name) !== $name
        ) {
            throw StorageException::invalidFileName($name);
        }
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
        self::assertSegment($tableName);

        $dir = $this->dbPath . '/' . $tableName;

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

    /**
     * Enforces the writer contract: a full rewrite must run under the table
     * EX lock. A standalone storage built without a lock manager (test
     * helpers, one-off scripts) is exempt. A violation is a programming bug
     * in a caller, so it fails loudly here rather than corrupting concurrent
     * readers under a missing lock.
     */
    private function requireTableExLock(
        string $tableName,
        string $operation,
    ): void {
        if ($this->locks !== null && !$this->locks->isHeld($tableName, 'ex')) {
            throw StorageException::writeLockRequired(
                'NdjsonStorage::' . $operation,
                $tableName,
            );
        }
    }
}
