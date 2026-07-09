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

        return $row;
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
     * Fully rewrites the NDJSON file under an exclusive lock.
     *
     * @param array<int,array<string,null|scalar>> $records
     */
    public function write(
        string $tableName,
        string $fileName,
        array $records,
    ): void {
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

            ftruncate($handle, 0);
            rewind($handle);

            foreach (array_values($records) as $record) {
                $line = json_encode($record);

                if ($line === false) {
                    throw StorageException::invalidRecord(
                        $tableName,
                        json_last_error_msg(),
                    );
                }

                fwrite($handle, $line . "\n");
            }

            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Appends a single record to the end of an NDJSON file under an
     * exclusive lock.
     *
     * @param array<string,null|scalar> $record
     */
    public function append(
        string $tableName,
        string $fileName,
        array $record,
    ): void {
        $path = $this->resolvePath($tableName, $fileName);
        $this->ensureFileExists($path);

        $line = json_encode($record);

        if ($line === false) {
            throw StorageException::invalidRecord(
                $tableName,
                json_last_error_msg(),
            );
        }

        $handle = fopen($path, 'a');

        if ($handle === false) {
            throw StorageException::fileNotWritable($path);
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw StorageException::lockFailed($path);
            }

            fwrite($handle, $line . "\n");
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
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

        $result = file_put_contents($path, '');

        if ($result === false) {
            throw StorageException::fileNotWritable($path);
        }
    }

    /**
     * Returns whether the specified NDJSON file exists.
     */
    public function exists(string $tableName, string $fileName): bool
    {
        return file_exists($this->resolvePath($tableName, $fileName));
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
     * Writes raw byte contents to an NDJSON file under an exclusive lock.
     * Used by the restore service to copy file contents verbatim.
     */
    public function writeRaw(
        string $tableName,
        string $fileName,
        string $contents,
    ): void {
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

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $contents);
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
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
