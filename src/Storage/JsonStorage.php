<?php

declare(strict_types=1);

namespace AV\JsonProvider\Storage;

use AV\JsonProvider\Exception\StorageException;

/**
 * Storage for single JSON files at the DB root.
 * Used for information_schema.json, meta.json and similar service files.
 *
 * Layout: dbPath/<fileName> (no subdirectory).
 *
 * Contract: a file must exist for any operation except createFile().
 * Service files are created by createDatabase; user files — explicitly via
 * createFile.
 *
 * Atomic read-modify-write is supported via transaction(): the callback sees
 * current contents and decides whether to persist via handle->save().
 */
final class JsonStorage
{
    private TableLockManager | null $locks;

    /** @var array<string,true> files with a transaction in flight */
    private array $inTransaction = [];

    public function __construct(
        private readonly string $dbPath,
        TableLockManager | null $locks = null,
    ) {
        $this->locks = $locks;
    }

    /**
     * Factory for a brand-new storage: creates the DB root directory and
     * returns an instance.
     * Throws if the directory already exists or mkdir fails.
     *
     * Used only when creating a DB. Opening an existing storage —
     * `new JsonStorage($dbPath)`.
     */
    public static function createRoot(string $dbPath): self
    {
        if (file_exists($dbPath)) {
            throw StorageException::databaseAlreadyExists($dbPath);
        }

        if (!mkdir($dbPath, 0755, true) && !is_dir($dbPath)) {
            throw StorageException::fileNotWritable($dbPath);
        }

        return new self($dbPath);
    }

    /**
     * Reads a JSON file and returns the decoded data.
     * Throws if the file is missing or contents are invalid.
     *
     * @return array<mixed>
     */
    public function read(string $fileName): array
    {
        $path = $this->resolvePath($fileName);
        $this->ensureFileExists($path);

        $raw = file_get_contents($path);

        if ($raw === false) {
            throw StorageException::fileNotReadable($path);
        }

        if ($raw === '') {
            return [];
        }

        $data = json_decode($raw, true);

        if (!\is_array($data)) {
            throw StorageException::invalidJson($path);
        }

        return $data;
    }

    /**
     * Fully rewrites the JSON file atomically (tmp+fsync+rename) under the
     * same sidecar lock .locks/<fileName>.lock that transaction() takes, so
     * a plain write and a concurrent read-modify-write never interleave.
     *
     * @param array<mixed> $data
     */
    public function write(string $fileName, array $data): void
    {
        $path = $this->resolvePath($fileName);
        $this->ensureFileExists($path);

        $json = json_encode($data, JSON_PRETTY_PRINT);

        if ($json === false) {
            throw StorageException::fileNotWritable($path);
        }

        $this->locks()->withServiceFile(
            $fileName,
            static fn (): int => AtomicFileWriter::write($path, $json),
        );
    }

    /**
     * Atomic read-modify-write under the sidecar lock
     * .locks/<fileName>.lock.
     *
     * The callback receives current file contents and a handle. To persist
     * changes, call $handle->save(). If save() is not called, the file is
     * left unchanged (useful for read-only operations under the lock).
     *
     * The file is read by path inside the lock (not through a pre-opened
     * descriptor), so the callback always sees the bytes of the current
     * inode even right after another process replaced the file via rename.
     * Saving goes through tmp+fsync+rename — a crash mid-save leaves the
     * previous contents intact.
     *
     * @template T
     *
     * @param callable(array<mixed>, JsonStorageTxHandle): T $callback
     *
     * @return T
     */
    public function transaction(string $fileName, callable $callback): mixed
    {
        $path = $this->resolvePath($fileName);

        if (isset($this->inTransaction[$fileName])) {
            throw StorageException::lockOrderViolation(
                'nested transaction on "' . $fileName . '"',
            );
        }

        return $this->locks()->withServiceFile(
            $fileName,
            function () use ($path, $fileName, $callback): mixed {
                $this->inTransaction[$fileName] = true;

                try {
                    return $this->runTransaction($path, $callback);
                } finally {
                    unset($this->inTransaction[$fileName]);
                }
            },
        );
    }

    /**
     * Creates a new JSON file with the given initial contents.
     * Throws if the file already exists.
     *
     * @param array<mixed> $initialData
     */
    public function createFile(string $fileName, array $initialData = []): void
    {
        $this->ensureDbDir();

        $path = $this->resolvePath($fileName);

        if (file_exists($path)) {
            throw StorageException::tableFileExists($path);
        }

        $json = json_encode($initialData, JSON_PRETTY_PRINT);

        if ($json === false) {
            throw StorageException::fileNotWritable($path);
        }

        AtomicFileWriter::write($path, $json);
    }

    /**
     * Creates a new JSON file with an empty top-level object ("{}").
     * Throws if the file already exists.
     *
     * Separate from createFile() because an empty associative PHP array
     * serializes as "[]", not "{}", and meta files require an object.
     */
    public function createObjectFile(string $fileName): void
    {
        $this->ensureDbDir();

        $path = $this->resolvePath($fileName);

        if (file_exists($path)) {
            throw StorageException::tableFileExists($path);
        }

        AtomicFileWriter::write($path, "{}\n");
    }

    /**
     * Returns whether the given JSON file exists.
     */
    public function exists(string $fileName): bool
    {
        return file_exists($this->resolvePath($fileName));
    }

    /**
     * Returns the file's current mtime, size and inode, bypassing the PHP
     * stat cache. Used as a cheap change marker for cached readers: mtime
     * alone has second granularity and two same-length writes can share a
     * size, but every atomic save rename()s a fresh temp file, so the inode
     * changes on every write.
     *
     * @return array{mtime: int, size: int, ino: int}
     */
    public function stat(string $fileName): array
    {
        $path = $this->resolvePath($fileName);
        $this->ensureFileExists($path);

        clearstatcache(true, $path);
        $stat = @stat($path);

        if ($stat === false) {
            throw StorageException::fileNotReadable($path);
        }

        return [
            'mtime' => $stat['mtime'],
            'size'  => $stat['size'],
            'ino'   => $stat['ino'],
        ];
    }

    /**
     * Lists entries directly inside the DB root (no recursion).
     * Returns a list of [name, isDir] pairs. Hidden entries (starting with '.')
     * are skipped.
     *
     * @return array<int,array{name:string, isDir:bool}>
     */
    public function listRootEntries(): array
    {
        if (!is_dir($this->dbPath)) {
            return [];
        }

        $entries = scandir($this->dbPath);

        if ($entries === false) {
            return [];
        }

        $result = [];

        foreach ($entries as $entry) {
            if (
                $entry === '.'
                || $entry === '..'
                || str_starts_with($entry, '.')
            ) {
                continue;
            }

            $full = $this->dbPath . '/' . $entry;
            $result[] = ['name' => $entry, 'isDir' => is_dir($full)];
        }

        usort(
            $result,
            static fn (array $a, array $b): int => strcmp(
                $a['name'],
                $b['name'],
            ),
        );

        return $result;
    }

    /**
     * Deletes a JSON file at the DB root. Idempotent — missing file is a no-op.
     * Throws on real I/O failure.
     */
    public function deleteFile(string $fileName): void
    {
        $path = $this->resolvePath($fileName);

        if (!file_exists($path)) {
            return;
        }

        if (!unlink($path)) {
            throw StorageException::fileNotWritable($path);
        }
    }

    /**
     * Body of transaction(): fresh read by path, callback, atomic save.
     *
     * @template T
     *
     * @param callable(array<mixed>, JsonStorageTxHandle): T $callback
     *
     * @return T
     */
    private function runTransaction(string $path, callable $callback): mixed
    {
        $this->ensureFileExists($path);

        $raw = file_get_contents($path);

        if ($raw === false) {
            throw StorageException::fileNotReadable($path);
        }

        if ($raw === '') {
            $data = [];
        } else {
            $decoded = json_decode($raw, true);

            if (!\is_array($decoded)) {
                throw StorageException::invalidJson($path);
            }

            $data = $decoded;
        }

        $tx = new JsonStorageTxHandle();
        $result = $callback($data, $tx);

        if ($tx->hasPendingSave()) {
            $json = json_encode($tx->pendingData(), JSON_PRETTY_PRINT);

            if ($json === false) {
                throw StorageException::fileNotWritable($path);
            }

            AtomicFileWriter::write($path, $json);
        }

        return $result;
    }

    /**
     * Resolves the absolute path: dbPath/<fileName>.
     * Path-traversal guard: the name must not contain separators.
     */
    private function resolvePath(string $fileName): string
    {
        $clean = basename($fileName);

        if ($fileName === '' || $clean !== $fileName) {
            throw StorageException::invalidFileName($fileName);
        }

        return $this->dbPath . '/' . $clean;
    }

    private function ensureDbDir(): void
    {
        if (!is_dir($this->dbPath)) {
            throw StorageException::fileNotWritable($this->dbPath);
        }
    }

    /**
     * Lock manager for sidecar (level 3) service-file locks. Inject the
     * provider-level manager so held-set re-entrancy sees table and sidecar
     * locks together; standalone usage falls back to a lazily created own
     * instance (cross-process exclusion still holds — flock grants are per
     * open file description).
     */
    private function locks(): TableLockManager
    {
        return $this->locks ??= new TableLockManager($this->dbPath);
    }

    private function ensureFileExists(string $path): void
    {
        if (!file_exists($path)) {
            throw StorageException::fileNotReadable($path);
        }
    }
}
