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
    public function __construct(
        private readonly string $dbPath,
    ) {}

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
     * Fully rewrites the JSON file under an exclusive lock.
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
            fwrite($handle, $json);
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Atomic read-modify-write under an exclusive lock.
     *
     * The callback receives current file contents and a handle. To persist
     * changes, call $handle->save(). If save() is not called, the file is
     * left unchanged (useful for read-only operations under the lock).
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
        $this->ensureFileExists($path);

        $handle = fopen($path, 'c+');

        if ($handle === false) {
            throw StorageException::fileNotWritable($path);
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw StorageException::lockFailed($path);
            }

            $raw = stream_get_contents($handle);

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
                $next = $tx->pendingData();
                $json = json_encode($next, JSON_PRETTY_PRINT);

                if ($json === false) {
                    throw StorageException::fileNotWritable($path);
                }

                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, $json);
                fflush($handle);
            }

            flock($handle, LOCK_UN);

            return $result;
        } finally {
            fclose($handle);
        }
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

        if (file_put_contents($path, $json) === false) {
            throw StorageException::fileNotWritable($path);
        }
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

        if (file_put_contents($path, "{}\n") === false) {
            throw StorageException::fileNotWritable($path);
        }
    }

    /**
     * Returns whether the given JSON file exists.
     */
    public function exists(string $fileName): bool
    {
        return file_exists($this->resolvePath($fileName));
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

    private function ensureFileExists(string $path): void
    {
        if (!file_exists($path)) {
            throw StorageException::fileNotReadable($path);
        }
    }
}
