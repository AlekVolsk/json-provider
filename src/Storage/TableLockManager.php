<?php

declare(strict_types=1);

namespace AV\JsonProvider\Storage;

use AV\JsonProvider\Exception\StorageException;

/**
 * Single owner of inter-process locks for one database directory.
 *
 * Lock files live in dbPath/.locks/ (dot-directory: invisible to
 * listRootEntries, hence never part of backup or validation). Every lock is
 * a flock() on a persistent empty file, one file per lock, with a level
 * prefix so the three namespaces can never collide:
 *
 *   level 1: db.lock              — whole-database lock;
 *   level 2: table.<name>.lock    — per-table lock;
 *   level 3: svc.<name>.lock      — sidecar for a root service file
 *                                   (meta.json, information_schema.json).
 *
 * Total acquisition order: database first (SH for any DML writer, EX for
 * DDL/restore/backup/repair), then tables in ascending name order (EX for
 * written tables, SH for tables only read inside the critical section), then
 * leaf sidecars. Leaf locks are short and non-composable: acquiring level 1-2
 * locks while holding a leaf lock is forbidden, as is holding two different
 * leaf locks at once.
 *
 * Re-entrancy is tracked per process instance via a held-set: a nested
 * withLocks() call passes when its tables are a subset of the held ones and
 * no requested mode is stronger than the held one. Fresh tables on top of a
 * non-empty held table-set, an SH->EX upgrade, or a database lock on top of
 * held table locks all raise LOCK_ORDER_VIOLATION.
 *
 * Acquisition is non-blocking flock in a usleep loop with a single deadline
 * per frame (default 30 s) -> LOCK_TIMEOUT. flock() failing for a reason
 * other than contention -> LOCK_FAILED. After a successful flock the handle
 * is verified to still be the file at the lock path (inode check): a lock
 * taken on an inode orphaned by deleteTableLock() is released and re-taken
 * on the current file, so a cached handle can never bypass a newer lock
 * file. Locks die with the process (flock semantics), so a crashed holder
 * never leaves the database locked.
 *
 * Caveats: flock handles are inherited by child processes — a child spawned
 * from inside a critical section (proc_open, exec) keeps the lock alive
 * until the child exits, even after the parent releases. Do not spawn
 * long-lived children while holding locks. Callbacks must run to completion
 * within the frame: returning a Generator or suspending a Fiber inside $fn
 * escapes the critical section while the locks are already released.
 *
 * POSIX-only: flock over network filesystems (NFS) and Windows are not
 * supported.
 */
final class TableLockManager
{
    private const string DB_LOCK_FILE = 'db.lock';
    private const string MODE_SH = 'sh';
    private const string MODE_EX = 'ex';
    private const int RETRY_INTERVAL_MICROSECONDS = 2_000;

    /** @var array<string,resource> lock file name => open handle */
    private array $handles = [];

    /** @var array<string,array{mode:string,depth:int}> held table locks */
    private array $heldTables = [];

    private string | null $dbMode = null;

    private int $dbDepth = 0;

    private string | null $heldServiceFile = null;

    private int $serviceDepth = 0;

    public function __construct(
        private readonly string $dbPath,
        private readonly float $timeoutSeconds = 30.0,
    ) {}

    public function __destruct()
    {
        foreach ($this->handles as $handle) {
            fclose($handle);
        }
    }

    /**
     * Runs $fn while holding the requested locks: optional database lock
     * ($dbMode 'sh'|'ex'|null) plus per-table locks ('name' => 'sh'|'ex').
     * Tables are acquired in ascending name order; everything acquired here
     * is released when $fn returns or throws. The acquisition deadline is
     * shared by all locks of the frame.
     *
     * @template T
     *
     * @param array<int|string,string> $tables table name => 'sh'|'ex'
     *                                         (a purely numeric name
     *                                         arrives as an int key)
     * @param callable(): T            $fn
     *
     * @return T
     */
    public function withLocks(
        array $tables,
        string | null $dbMode,
        callable $fn,
    ): mixed {
        if ($this->serviceDepth > 0) {
            throw StorageException::lockOrderViolation(
                'table/database locks cannot be acquired while a service-file '
                    . 'lock is held',
            );
        }

        if ($dbMode !== null) {
            self::assertMode($dbMode);
        }

        $normalized = [];

        foreach ($tables as $name => $mode) {
            $name = (string)$name;
            self::assertName($name);
            self::assertMode($mode);
            $normalized[$name] = $mode;
        }

        $tables = $normalized;

        $deadline = $this->frameDeadline();
        $dbAcquired = false;
        $dbReentered = false;

        /** @var list<string> $freshTables */
        $freshTables = [];

        /** @var list<string> $reenteredTables */
        $reenteredTables = [];

        try {
            if ($dbMode !== null) {
                if ($this->dbMode !== null) {
                    if (
                        $dbMode === self::MODE_EX
                        && $this->dbMode === self::MODE_SH
                    ) {
                        throw StorageException::lockOrderViolation(
                            'database lock upgrade sh -> ex',
                        );
                    }

                    $this->dbDepth++;
                    $dbReentered = true;
                } else {
                    if ($this->heldTables !== []) {
                        throw StorageException::lockOrderViolation(
                            'database lock requested while table locks '
                                . 'are held',
                        );
                    }

                    $this->acquire(
                        self::DB_LOCK_FILE,
                        $dbMode,
                        'database',
                        $deadline,
                    );
                    $this->dbMode = $dbMode;
                    $this->dbDepth = 1;
                    $dbAcquired = true;
                }
            }

            $freshNames = [];

            foreach ($tables as $name => $mode) {
                $held = $this->heldTables[$name] ?? null;

                if ($held !== null) {
                    if (
                        $mode === self::MODE_EX
                        && $held['mode'] === self::MODE_SH
                    ) {
                        throw StorageException::lockOrderViolation(
                            'table "' . $name . '" lock upgrade sh -> ex',
                        );
                    }

                    continue;
                }

                if ($this->heldTables !== []) {
                    throw StorageException::lockOrderViolation(
                        'table "' . $name . '" is outside the held lock set',
                    );
                }

                $freshNames[] = $name;
            }

            foreach (array_keys($tables) as $name) {
                if (isset($this->heldTables[$name])) {
                    $this->heldTables[$name]['depth']++;
                    $reenteredTables[] = $name;
                }
            }

            sort($freshNames, SORT_STRING);

            foreach ($freshNames as $name) {
                $this->acquire(
                    self::tableLockFile($name),
                    $tables[$name],
                    'table "' . $name . '"',
                    $deadline,
                );
                $this->heldTables[$name] = [
                    'mode'  => $tables[$name],
                    'depth' => 1,
                ];
                $freshTables[] = $name;
            }
        } catch (\Throwable $e) {
            $this->rollback(
                $dbAcquired,
                $dbReentered,
                $freshTables,
                $reenteredTables,
            );

            throw $e;
        }

        try {
            return $fn();
        } finally {
            $this->rollback(
                $dbAcquired,
                $dbReentered,
                $freshTables,
                $reenteredTables,
            );
        }
    }

    /**
     * Runs $fn under the exclusive database lock (level 1, no table locks).
     * Mutating operations must additionally take EX locks on every table they
     * touch: the database EX lock alone does not exclude table-SH readers.
     *
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    public function withDatabase(callable $fn): mixed
    {
        return $this->withLocks([], self::MODE_EX, $fn);
    }

    /**
     * Runs $fn under the exclusive leaf lock .locks/svc.<fileName>.lock
     * guarding a root service file. Leaf locks are non-composable: nesting a
     * different service file is forbidden; the same file re-enters.
     *
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    public function withServiceFile(string $fileName, callable $fn): mixed
    {
        self::assertName($fileName);

        if ($this->heldServiceFile !== null) {
            if ($this->heldServiceFile !== $fileName) {
                throw StorageException::lockOrderViolation(
                    'service-file lock "' . $fileName . '" requested while "'
                        . $this->heldServiceFile . '" is held',
                );
            }

            $this->serviceDepth++;

            try {
                return $fn();
            } finally {
                $this->serviceDepth--;
            }
        }

        $this->acquire(
            self::serviceLockFile($fileName),
            self::MODE_EX,
            'service file "' . $fileName . '"',
            $this->frameDeadline(),
        );
        $this->heldServiceFile = $fileName;
        $this->serviceDepth = 1;

        try {
            return $fn();
        } finally {
            $this->serviceDepth = 0;
            $this->heldServiceFile = null;
            $this->release(self::serviceLockFile($fileName));
        }
    }

    /**
     * Whether this process currently holds a table lock. 'ex' is true only
     * for a real table EX in the held-set (a database EX does not imply it);
     * 'sh' is true for any held mode (EX covers SH).
     */
    public function isHeld(string $tableName, string $mode): bool
    {
        self::assertMode($mode);

        $held = $this->heldTables[$tableName] ?? null;

        if ($held === null) {
            return false;
        }

        return $mode === self::MODE_SH || $held['mode'] === self::MODE_EX;
    }

    /**
     * Whether this process currently holds the database lock in at least the
     * given mode (EX covers SH).
     */
    public function isDatabaseHeld(string $mode): bool
    {
        self::assertMode($mode);

        if ($this->dbMode === null) {
            return false;
        }

        return $mode === self::MODE_SH || $this->dbMode === self::MODE_EX;
    }

    /**
     * Removes the per-table lock file. Requires the caller to hold the
     * table EX lock (LOCK_ORDER_VIOLATION otherwise); dropTable calls this
     * as the last step of the critical section, after the table's files,
     * schema entry and meta entry are gone.
     *
     * Only the directory entry is unlinked — the open handle keeps the EX
     * lock alive on the orphaned inode until the frame releases, so a waiter
     * parked on the old inode cannot enter the critical section early. Any
     * process that later locks an orphaned inode detects the replacement via
     * the inode check in acquire() and re-takes the lock on the current
     * file.
     */
    public function deleteTableLock(string $tableName): void
    {
        self::assertName($tableName);

        if (!$this->isHeld($tableName, self::MODE_EX)) {
            throw StorageException::lockOrderViolation(
                'deleteTableLock("' . $tableName
                    . '") requires the table EX lock',
            );
        }

        $path = $this->locksDir() . '/' . self::tableLockFile($tableName);

        if (file_exists($path)) {
            unlink($path);
        }
    }

    private static function tableLockFile(string $tableName): string
    {
        return 'table.' . $tableName . '.lock';
    }

    private static function serviceLockFile(string $fileName): string
    {
        return 'svc.' . $fileName . '.lock';
    }

    private static function assertMode(string $mode): void
    {
        if ($mode !== self::MODE_SH && $mode !== self::MODE_EX) {
            throw new \InvalidArgumentException(
                'Lock mode must be "sh" or "ex", got "' . $mode . '"',
            );
        }
    }

    /**
     * Path-traversal guard, mirroring the storage resolvePath rules: a lock
     * subject must be a plain file-name-safe token.
     */
    private static function assertName(string $name): void
    {
        if (
            $name === ''
            || $name === '.'
            || $name === '..'
            || basename($name) !== $name
        ) {
            throw StorageException::invalidFileName($name);
        }
    }

    /**
     * Releases everything acquired by one withLocks() frame, in reverse
     * order: fresh tables (descending name order), then the database lock.
     *
     * @param list<string> $freshTables
     * @param list<string> $reenteredTables
     */
    private function rollback(
        bool $dbAcquired,
        bool $dbReentered,
        array $freshTables,
        array $reenteredTables,
    ): void {
        foreach ($reenteredTables as $name) {
            if (isset($this->heldTables[$name])) {
                $this->heldTables[$name]['depth']--;
            }
        }

        rsort($freshTables, SORT_STRING);

        foreach ($freshTables as $name) {
            unset($this->heldTables[$name]);
            $this->release(self::tableLockFile($name));
        }

        if ($dbReentered) {
            $this->dbDepth--;
        }

        if ($dbAcquired) {
            \assert(
                $this->dbDepth === 1,
                'database lock depth out of sync at frame release',
            );
            $this->dbMode = null;
            $this->dbDepth = 0;
            $this->release(self::DB_LOCK_FILE);
        }
    }

    /**
     * Non-blocking flock loop with a shared frame deadline. Contention keeps
     * retrying until the timeout; any other flock failure is fatal
     * immediately. A lock granted on an inode that no longer backs the lock
     * path (the file was replaced) is released and re-taken on the current
     * file.
     */
    private function acquire(
        string $lockFile,
        string $mode,
        string $subject,
        int $deadline,
    ): void {
        $operation = ($mode === self::MODE_EX ? LOCK_EX : LOCK_SH) | LOCK_NB;

        while (true) {
            $handle = $this->handle($lockFile);
            $wouldBlock = 0;

            if (flock($handle, $operation, $wouldBlock)) {
                if ($this->handleMatchesPath($handle, $lockFile)) {
                    return;
                }

                flock($handle, LOCK_UN);
                $this->evictHandle($lockFile);
            } elseif ($wouldBlock !== 1) {
                throw StorageException::lockFailed(
                    $this->locksDir() . '/' . $lockFile,
                );
            }

            if (hrtime(true) >= $deadline) {
                throw StorageException::lockTimeout(
                    $mode,
                    $subject,
                    self::formatSeconds($this->timeoutSeconds),
                );
            }

            usleep(self::RETRY_INTERVAL_MICROSECONDS);
        }
    }

    private function release(string $lockFile): void
    {
        if (!isset($this->handles[$lockFile])) {
            return;
        }

        flock($this->handles[$lockFile], LOCK_UN);
    }

    private function evictHandle(string $lockFile): void
    {
        if (!isset($this->handles[$lockFile])) {
            return;
        }

        fclose($this->handles[$lockFile]);
        unset($this->handles[$lockFile]);
    }

    /**
     * Whether the open handle still refers to the file currently at the
     * lock path (same device+inode). False when the file was unlinked or
     * replaced since the handle was opened.
     *
     * @param resource $handle
     */
    private function handleMatchesPath(mixed $handle, string $lockFile): bool
    {
        $handleStat = fstat($handle);

        if ($handleStat === false) {
            return false;
        }

        $path = $this->locksDir() . '/' . $lockFile;
        clearstatcache(true, $path);
        $pathStat = @stat($path);

        if ($pathStat === false) {
            return false;
        }

        return $handleStat['dev'] === $pathStat['dev']
            && $handleStat['ino'] === $pathStat['ino'];
    }

    /**
     * @return resource
     */
    private function handle(string $lockFile): mixed
    {
        if (isset($this->handles[$lockFile])) {
            return $this->handles[$lockFile];
        }

        $dir = $this->locksDir();

        if (!is_dir($dir) && !@mkdir($dir, 0755) && !is_dir($dir)) {
            throw StorageException::fileNotWritable($dir);
        }

        $path = $dir . '/' . $lockFile;
        $handle = fopen($path, 'c');

        if ($handle === false) {
            throw StorageException::fileNotWritable($path);
        }

        $this->handles[$lockFile] = $handle;

        return $handle;
    }

    private function frameDeadline(): int
    {
        return hrtime(true) + (int)($this->timeoutSeconds * 1_000_000_000);
    }

    private function locksDir(): string
    {
        return $this->dbPath . '/.locks';
    }

    private static function formatSeconds(float $seconds): string
    {
        $formatted = rtrim(rtrim(\sprintf('%.3F', $seconds), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
