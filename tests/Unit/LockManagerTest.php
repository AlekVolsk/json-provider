<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\StorageException;
use AV\JsonProvider\Storage\TableLockManager;
use Testo\Assert;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for TableLockManager: lock hierarchy (db -> tables -> service files),
 * re-entrancy via the held-set, ordering violations, timeout ceiling, and
 * real cross-process exclusion semantics (flock) via child PHP processes.
 */
final class LockManagerTest
{
    private const string TMP_DIR = '/tmp/jp-lock-tests';

    /** @var null|array{proc: resource, pipes: array<int,resource>} */
    private array | null $pendingChild = null;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::TMP_DIR);
        mkdir(self::TMP_DIR, 0755, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::TMP_DIR);
    }

    // -- basic behaviour ---------------------------------------------------

    #[Test]
    public function withLocksRunsCallbackAndReturnsItsValue(): void
    {
        $manager = new TableLockManager(self::TMP_DIR);

        $result = $manager->withLocks(
            ['users' => 'ex'],
            'sh',
            static fn (): string => 'payload',
        );

        Assert::same($result, 'payload');
    }

    #[Test]
    public function locksDirIsCreatedLazilyAsHiddenDirectory(): void
    {
        $manager = new TableLockManager(self::TMP_DIR);

        Assert::false(is_dir(self::TMP_DIR . '/.locks'));

        $manager->withLocks(['users' => 'ex'], null, static fn (): int => 1);

        Assert::true(is_dir(self::TMP_DIR . '/.locks'));
    }

    #[Test]
    public function lockFilesArePersistentAndEmptyAfterRelease(): void
    {
        $manager = new TableLockManager(self::TMP_DIR);

        $manager->withLocks(['users' => 'ex'], 'sh', static fn (): int => 1);

        $tableLock = $this->tableLockPath('users');
        $dbLock = self::TMP_DIR . '/.locks/db.lock';

        Assert::true(file_exists($tableLock));
        Assert::true(file_exists($dbLock));
        Assert::same(filesize($tableLock), 0);
        Assert::same(filesize($dbLock), 0);
    }

    #[Test]
    public function locksAreReleasedAfterCallbackReturns(): void
    {
        $manager = new TableLockManager(self::TMP_DIR);

        $manager->withLocks(['users' => 'ex'], 'ex', static fn (): int => 1);

        Assert::true($this->probeFree($this->tableLockPath('users'), 'ex'));
        Assert::true($this->probeFree($this->dbLockPath(), 'ex'));
    }

    #[Test]
    public function locksAreReleasedWhenCallbackThrows(): void
    {
        $manager = new TableLockManager(self::TMP_DIR);

        $caught = null;

        try {
            $manager->withLocks(
                ['users' => 'ex'],
                'sh',
                static function (): void {
                    throw new \RuntimeException('boom');
                },
            );
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        Assert::same($caught->getMessage(), 'boom');

        Assert::false($manager->isHeld('users', 'sh'));
        Assert::true($this->probeFree($this->tableLockPath('users'), 'ex'));
        Assert::true($this->probeFree($this->dbLockPath(), 'ex'));
    }

    #[Test]
    public function invalidModeIsRejected(): void
    {
        $manager = new TableLockManager(self::TMP_DIR);

        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('Lock mode');

        $manager->withLocks(
            ['users' => 'shared'],
            null,
            static fn (): int => 1,
        );
    }

    // -- isHeld semantics --------------------------------------------------

    #[Test]
    public function isHeldReflectsTableLocksOnly(): void
    {
        $manager = new TableLockManager(self::TMP_DIR);

        Assert::false($manager->isHeld('users', 'sh'));

        $manager->withLocks(
            ['users' => 'ex', 'logs' => 'sh'],
            'sh',
            static function () use ($manager): void {
                Assert::true($manager->isHeld('users', 'ex'));
                Assert::true($manager->isHeld('users', 'sh'));
                Assert::false($manager->isHeld('logs', 'ex'));
                Assert::true($manager->isHeld('logs', 'sh'));
                Assert::false($manager->isHeld('other', 'sh'));
            },
        );

        Assert::false($manager->isHeld('users', 'sh'));
        Assert::false($manager->isHeld('logs', 'sh'));
    }

    #[Test]
    public function databaseExDoesNotImplyTableEx(): void
    {
        $manager = new TableLockManager(self::TMP_DIR);

        $manager->withDatabase(static function () use ($manager): void {
            Assert::true($manager->isDatabaseHeld('ex'));
            Assert::true($manager->isDatabaseHeld('sh'));
            Assert::false($manager->isHeld('users', 'ex'));
            Assert::false($manager->isHeld('users', 'sh'));
        });

        Assert::false($manager->isDatabaseHeld('sh'));
    }

    #[Test]
    public function databaseShIsCoveredByEx(): void
    {
        $manager = new TableLockManager(self::TMP_DIR);

        $manager->withLocks([], 'sh', static function () use ($manager): void {
            Assert::true($manager->isDatabaseHeld('sh'));
            Assert::false($manager->isDatabaseHeld('ex'));
        });
    }

    // -- re-entrancy -------------------------------------------------------

    #[Test]
    public function nestedSubsetPassesAndInnerExitKeepsOuterLockHeld(): void
    {
        $manager = new TableLockManager(self::TMP_DIR);

        $manager->withLocks(
            ['users' => 'ex'],
            'sh',
            function () use ($manager): void {
                $inner = $manager->withLocks(
                    ['users' => 'ex'],
                    'sh',
                    static fn (): string => 'nested',
                );

                Assert::same($inner, 'nested');

                // The inner frame must not have dropped the outer flock.
                Assert::false($this->probeFree(
                    $this->tableLockPath('users'),
                    'sh',
                ));
                Assert::true($manager->isHeld('users', 'ex'));
            },
        );

        Assert::true($this->probeFree($this->tableLockPath('users'), 'ex'));
    }

    #[Test]
    public function nestedShRequestAgainstHeldExPasses(): void
    {
        $manager = new TableLockManager(self::TMP_DIR);

        $result = $manager->withLocks(
            ['users' => 'ex'],
            'ex',
            static fn (): mixed => $manager->withLocks(
                ['users' => 'sh'],
                'sh',
                static fn (): int => 42,
            ),
        );

        Assert::same($result, 42);
    }

    #[Test]
    public function nestedTablesUnderHeldDatabaseLockPass(): void
    {
        $manager = new TableLockManager(self::TMP_DIR);

        $result = $manager->withDatabase(
            static fn (): mixed => $manager->withLocks(
                ['users' => 'ex', 'logs' => 'ex'],
                null,
                static fn (): string => 'ddl',
            ),
        );

        Assert::same($result, 'ddl');
        Assert::false($manager->isHeld('users', 'sh'));
        Assert::false($manager->isDatabaseHeld('sh'));
    }

    // -- ordering violations -----------------------------------------------

    #[Test]
    public function tableUpgradeShToExThrows(): void
    {
        $manager = new TableLockManager(self::TMP_DIR);

        try {
            $manager->withLocks(
                ['users' => 'sh'],
                null,
                static fn (): mixed => $manager->withLocks(
                    ['users' => 'ex'],
                    null,
                    static fn (): int => 1,
                ),
            );
            Assert::fail('upgrade must throw');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'LOCK_ORDER_VIOLATION');
            Assert::string($e->getMessage())->contains('upgrade');
        }

        Assert::false($manager->isHeld('users', 'sh'));
    }

    #[Test]
    public function freshTableOutsideNonEmptyHeldSetThrows(): void
    {
        $manager = new TableLockManager(self::TMP_DIR);

        try {
            $manager->withLocks(
                ['users' => 'ex'],
                'sh',
                static fn (): mixed => $manager->withLocks(
                    ['logs' => 'ex'],
                    'sh',
                    static fn (): int => 1,
                ),
            );
            Assert::fail('fresh table on top of held set must throw');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'LOCK_ORDER_VIOLATION');
            Assert::string($e->getMessage())
                ->contains('outside the held lock set');
        }

        Assert::false($manager->isHeld('users', 'sh'));
        Assert::false($manager->isHeld('logs', 'sh'));
    }

    #[Test]
    public function databaseLockOnTopOfHeldTablesThrows(): void
    {
        $manager = new TableLockManager(self::TMP_DIR);

        try {
            $manager->withLocks(
                ['users' => 'ex'],
                null,
                static fn (): mixed => $manager->withLocks(
                    [],
                    'sh',
                    static fn (): int => 1,
                ),
            );
            Assert::fail('db lock after tables must throw');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'LOCK_ORDER_VIOLATION');
        }

        Assert::false($manager->isHeld('users', 'sh'));
    }

    #[Test]
    public function databaseUpgradeShToExThrows(): void
    {
        $manager = new TableLockManager(self::TMP_DIR);

        try {
            $manager->withLocks(
                [],
                'sh',
                static fn (): mixed => $manager->withDatabase(
                    static fn (): int => 1,
                ),
            );
            Assert::fail('db upgrade must throw');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'LOCK_ORDER_VIOLATION');
        }

        Assert::false($manager->isDatabaseHeld('sh'));
    }

    #[Test]
    public function partialAcquisitionFailureReleasesEverything(): void
    {
        // A frame that freshly acquired the db lock, then timed out on a
        // contended table, must release the db lock on the way out.
        $holder = $this->spawnFlockHolder(
            $this->tableLockPath('users'),
            'ex',
        );

        try {
            $manager = new TableLockManager(self::TMP_DIR, 0.1);

            try {
                $manager->withLocks(
                    ['users' => 'ex'],
                    'sh',
                    static fn (): int => 1,
                );
                Assert::fail('must time out');
            } catch (StorageException $e) {
                Assert::same($e->getErrorKey(), 'LOCK_TIMEOUT');
            }

            Assert::false($manager->isDatabaseHeld('sh'));
            Assert::true($this->probeFree($this->dbLockPath(), 'ex'));
        } finally {
            $this->releaseHolder($holder);
        }
    }

    #[Test]
    public function timeoutOnSecondFreshTableReleasesTheFirst(): void
    {
        // Sorted order acquires 'aaa' first, then parks on contended 'bbb':
        // the timeout must release the already-acquired 'aaa'.
        $holder = $this->spawnFlockHolder($this->tableLockPath('bbb'), 'ex');

        try {
            $manager = new TableLockManager(self::TMP_DIR, 0.1);

            try {
                $manager->withLocks(
                    ['bbb' => 'ex', 'aaa' => 'ex'],
                    null,
                    static fn (): int => 1,
                );
                Assert::fail('must time out');
            } catch (StorageException $e) {
                Assert::same($e->getErrorKey(), 'LOCK_TIMEOUT');
            }

            Assert::false($manager->isHeld('aaa', 'sh'));
            Assert::true($this->probeFree($this->tableLockPath('aaa'), 'ex'));
        } finally {
            $this->releaseHolder($holder);
        }
    }

    #[Test]
    public function nestedFrameExceptionKeepsOuterLockHeld(): void
    {
        // The decrement path of a re-entered frame unwinding via an
        // exception must not release the outer lock.
        $manager = new TableLockManager(self::TMP_DIR);

        $manager->withLocks(
            ['users' => 'ex'],
            'sh',
            function () use ($manager): void {
                try {
                    $manager->withLocks(
                        ['users' => 'ex'],
                        'sh',
                        static function (): void {
                            throw new \RuntimeException('inner boom');
                        },
                    );
                } catch (\RuntimeException) {
                }

                Assert::true($manager->isHeld('users', 'ex'));
                Assert::true($manager->isDatabaseHeld('sh'));
                Assert::false($this->probeFree(
                    $this->tableLockPath('users'),
                    'sh',
                ));
            },
        );

        Assert::true($this->probeFree($this->tableLockPath('users'), 'ex'));
        Assert::true($this->probeFree($this->dbLockPath(), 'ex'));
    }

    #[Test]
    public function tripleNestedSubsetKeepsLockUntilOutermostExit(): void
    {
        $manager = new TableLockManager(self::TMP_DIR);

        $result = $manager->withLocks(
            ['users' => 'ex'],
            'sh',
            fn (): string => $manager->withLocks(
                ['users' => 'ex'],
                'sh',
                fn (): string => $manager->withLocks(
                    ['users' => 'sh'],
                    'sh',
                    function () use ($manager): string {
                        Assert::true($manager->isHeld('users', 'ex'));
                        Assert::false($this->probeFree(
                            $this->tableLockPath('users'),
                            'sh',
                        ));

                        return 'deep';
                    },
                ),
            ),
        );

        Assert::same($result, 'deep');

        Assert::true($this->probeFree($this->tableLockPath('users'), 'ex'));
    }

    #[Test]
    public function invalidLockSubjectNamesAreRejected(): void
    {
        $manager = new TableLockManager(self::TMP_DIR);

        foreach (['', '.', '..', 'a/b', '../escape'] as $bad) {
            try {
                $manager->withLocks(
                    [$bad => 'ex'],
                    null,
                    static fn (): int => 1,
                );
                Assert::fail('name "' . $bad . '" must be rejected');
            } catch (StorageException $e) {
                Assert::same($e->getErrorKey(), 'INVALID_FILE_NAME');
            }
        }

        try {
            $manager->withServiceFile('../db', static fn (): int => 1);
            Assert::fail('service name must be rejected');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'INVALID_FILE_NAME');
        }

        // Nothing escaped the .locks directory or the DB root.
        Assert::false(file_exists(self::TMP_DIR . '/escape.table.lock'));
        Assert::false(file_exists(\dirname(self::TMP_DIR) . '/db.lock'));
    }

    #[Test]
    public function lockFileNamespacesDoNotCollide(): void
    {
        // A service file named 'db' or '<table>.table' must not alias the
        // level-1/level-2 lock files.
        $manager = new TableLockManager(self::TMP_DIR);

        $manager->withServiceFile('db', static fn (): int => 1);
        $manager->withServiceFile('users.table', static fn (): int => 1);

        Assert::true(
            file_exists(self::TMP_DIR . '/.locks/svc.db.lock'),
        );
        Assert::true(
            file_exists(self::TMP_DIR . '/.locks/svc.users.table.lock'),
        );
        Assert::false(file_exists(self::TMP_DIR . '/.locks/db.lock'));
        Assert::false(file_exists($this->tableLockPath('users')));

        // And a service lock on 'db' does not exclude the database lock.
        $holder = $this->spawnFlockHolder(
            $this->serviceLockPath('db'),
            'ex',
        );

        try {
            $quick = new TableLockManager(self::TMP_DIR, 0.5);
            $result = $quick->withDatabase(static fn (): string => 'db-ok');
            Assert::same($result, 'db-ok');
        } finally {
            $this->releaseHolder($holder);
        }
    }

    // -- service-file (leaf) locks -----------------------------------------

    #[Test]
    public function serviceFileLockRunsAndReleases(): void
    {
        $manager = new TableLockManager(self::TMP_DIR);

        $result = $manager->withServiceFile(
            'meta.json',
            static fn (): string => 'svc',
        );

        Assert::same($result, 'svc');
        Assert::true($this->probeFree(
            $this->serviceLockPath('meta.json'),
            'ex',
        ));
    }

    #[Test]
    public function serviceFileSameFileReenters(): void
    {
        $manager = new TableLockManager(self::TMP_DIR);

        $result = $manager->withServiceFile(
            'meta.json',
            static fn (): mixed => $manager->withServiceFile(
                'meta.json',
                static fn (): int => 7,
            ),
        );

        Assert::same($result, 7);
        Assert::true($this->probeFree(
            $this->serviceLockPath('meta.json'),
            'ex',
        ));
    }

    #[Test]
    public function serviceFileDifferentFileNestingThrows(): void
    {
        $manager = new TableLockManager(self::TMP_DIR);

        try {
            $manager->withServiceFile(
                'meta.json',
                static fn (): mixed => $manager->withServiceFile(
                    'information_schema.json',
                    static fn (): int => 1,
                ),
            );
            Assert::fail('cross-leaf nesting must throw');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'LOCK_ORDER_VIOLATION');
        }

        Assert::true($this->probeFree(
            $this->serviceLockPath('meta.json'),
            'ex',
        ));
    }

    #[Test]
    public function tableLockUnderServiceFileLockThrows(): void
    {
        $manager = new TableLockManager(self::TMP_DIR);

        try {
            $manager->withServiceFile(
                'meta.json',
                static fn (): mixed => $manager->withLocks(
                    ['users' => 'ex'],
                    null,
                    static fn (): int => 1,
                ),
            );
            Assert::fail('level 1-2 on top of a leaf lock must throw');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'LOCK_ORDER_VIOLATION');
        }
    }

    #[Test]
    public function serviceFileUnderTableLockPasses(): void
    {
        $manager = new TableLockManager(self::TMP_DIR);

        $result = $manager->withLocks(
            ['users' => 'ex'],
            'sh',
            static fn (): mixed => $manager->withServiceFile(
                'meta.json',
                static fn (): string => 'commit',
            ),
        );

        Assert::same($result, 'commit');
    }

    // -- timeout -----------------------------------------------------------

    #[Test]
    public function contendedLockTimesOutWithInjectedCeiling(): void
    {
        $holder = $this->spawnFlockHolder(
            $this->tableLockPath('users'),
            'ex',
        );

        try {
            $manager = new TableLockManager(self::TMP_DIR, 0.15);
            $start = microtime(true);

            try {
                $manager->withLocks(
                    ['users' => 'ex'],
                    null,
                    static fn (): int => 1,
                );
                Assert::fail('contended lock must time out');
            } catch (StorageException $e) {
                Assert::same($e->getErrorKey(), 'LOCK_TIMEOUT');
                Assert::string($e->getMessage())->contains('0.15');
                Assert::string($e->getMessage())->contains('users');
            }

            $elapsed = microtime(true) - $start;
            Assert::float($elapsed)->greaterThan(0.14);
            Assert::float($elapsed)->lessThan(5.0);
        } finally {
            $this->releaseHolder($holder);
        }
    }

    // -- cross-process semantics -------------------------------------------

    #[Test]
    public function exclusiveBlocksBothExclusiveAndShared(): void
    {
        $holder = $this->spawnFlockHolder(
            $this->tableLockPath('users'),
            'ex',
        );

        try {
            $manager = new TableLockManager(self::TMP_DIR, 0.1);

            try {
                $manager->withLocks(
                    ['users' => 'ex'],
                    null,
                    static fn (): int => 1,
                );
                Assert::fail('EX vs EX must block');
            } catch (StorageException $e) {
                Assert::same($e->getErrorKey(), 'LOCK_TIMEOUT');
            }

            try {
                $manager->withLocks(
                    ['users' => 'sh'],
                    null,
                    static fn (): int => 1,
                );
                Assert::fail('EX vs SH must block');
            } catch (StorageException $e) {
                Assert::same($e->getErrorKey(), 'LOCK_TIMEOUT');
            }
        } finally {
            $this->releaseHolder($holder);
        }
    }

    #[Test]
    public function sharedDoesNotBlockSharedButBlocksExclusive(): void
    {
        $holder = $this->spawnFlockHolder(
            $this->tableLockPath('users'),
            'sh',
        );

        try {
            $manager = new TableLockManager(self::TMP_DIR, 0.1);

            $result = $manager->withLocks(
                ['users' => 'sh'],
                null,
                static fn (): string => 'shared-ok',
            );
            Assert::same($result, 'shared-ok');

            try {
                $manager->withLocks(
                    ['users' => 'ex'],
                    null,
                    static fn (): int => 1,
                );
                Assert::fail('SH vs EX must block');
            } catch (StorageException $e) {
                Assert::same($e->getErrorKey(), 'LOCK_TIMEOUT');
            }
        } finally {
            $this->releaseHolder($holder);
        }
    }

    #[Test]
    public function databaseShDoesNotBlockAnotherDatabaseSh(): void
    {
        $holder = $this->spawnFlockHolder($this->dbLockPath(), 'sh');

        try {
            $manager = new TableLockManager(self::TMP_DIR, 0.1);

            $result = $manager->withLocks(
                ['users' => 'ex'],
                'sh',
                static fn (): string => 'dml',
            );
            Assert::same($result, 'dml');

            try {
                $manager->withDatabase(static fn (): int => 1);
                Assert::fail('db SH vs db EX must block');
            } catch (StorageException $e) {
                Assert::same($e->getErrorKey(), 'LOCK_TIMEOUT');
            }
        } finally {
            $this->releaseHolder($holder);
        }
    }

    #[Test]
    public function holderDeathReleasesTheLock(): void
    {
        $holder = $this->spawnFlockHolder(
            $this->tableLockPath('users'),
            'ex',
        );

        proc_terminate($holder['proc'], 9);
        proc_close($holder['proc']);

        $manager = new TableLockManager(self::TMP_DIR, 5.0);
        $result = $manager->withLocks(
            ['users' => 'ex'],
            null,
            static fn (): string => 'after-death',
        );

        Assert::same($result, 'after-death');
    }

    #[Test]
    public function acquisitionOrderIsSortedByTableName(): void
    {
        // A child manager requests ['c', 'a', 'b'] while this process holds
        // 'b'. Sorted acquisition means the child grabs 'a' and then parks on
        // 'b' without ever touching 'c'. Once 'b' is released, the child
        // finishes the whole set.
        mkdir(self::TMP_DIR . '/.locks', 0755, true);
        $holder = $this->spawnFlockHolder($this->tableLockPath('b'), 'ex');

        $code = <<<'PHP'
            require $argv[1];
            $manager = new \AV\JsonProvider\Storage\TableLockManager(
                $argv[2],
                20.0,
            );
            $manager->withLocks(
                ['c' => 'ex', 'a' => 'ex', 'b' => 'ex'],
                null,
                static function (): void {
                    echo "inside\n";
                },
            );
            echo "done\n";
            PHP;

        $child = $this->spawnPhp($code, [
            \dirname(__DIR__, 2) . '/vendor/autoload.php',
            self::TMP_DIR,
        ]);

        try {
            // Wait until the child has taken 'a' (proof it started acquiring).
            $deadline = microtime(true) + 10.0;

            while ($this->probeFree($this->tableLockPath('a'), 'sh')) {
                if (microtime(true) > $deadline) {
                    Assert::fail('child never acquired table "a"');
                }

                usleep(2_000);
            }

            // While parked on 'b', 'c' must still be free: sorted order means
            // 'c' comes last.
            Assert::true($this->probeFree($this->tableLockPath('c'), 'sh'));

            $this->releaseHolder($holder);
            $holder = null;

            $stdout = $this->drainAndClose($child);
            Assert::string($stdout)->contains('inside');
            Assert::string($stdout)->contains('done');
        } finally {
            if ($holder !== null) {
                $this->releaseHolder($holder);
            }
        }

        Assert::true($this->probeFree($this->tableLockPath('a'), 'ex'));
        Assert::true($this->probeFree($this->tableLockPath('b'), 'ex'));
        Assert::true($this->probeFree($this->tableLockPath('c'), 'ex'));
    }

    #[Test]
    public function serviceFileLockExcludesAcrossProcesses(): void
    {
        $holder = $this->spawnFlockHolder(
            $this->serviceLockPath('meta.json'),
            'ex',
        );

        try {
            $manager = new TableLockManager(self::TMP_DIR, 0.1);

            try {
                $manager->withServiceFile('meta.json', static fn (): int => 1);
                Assert::fail('service-file lock must exclude');
            } catch (StorageException $e) {
                Assert::same($e->getErrorKey(), 'LOCK_TIMEOUT');
            }
        } finally {
            $this->releaseHolder($holder);
        }
    }

    // -- deleteTableLock ---------------------------------------------------

    #[Test]
    public function deleteTableLockRemovesFileAndAllowsReacquisition(): void
    {
        $manager = new TableLockManager(self::TMP_DIR);

        $manager->withLocks(
            ['users' => 'ex'],
            null,
            static function () use ($manager): void {
                $manager->deleteTableLock('users');
            },
        );

        Assert::false(file_exists($this->tableLockPath('users')));

        // A fresh lock cycle recreates the file and works.
        $result = $manager->withLocks(
            ['users' => 'ex'],
            null,
            static fn (): string => 'recreated',
        );
        Assert::same($result, 'recreated');
        Assert::true(file_exists($this->tableLockPath('users')));
    }

    #[Test]
    public function deleteTableLockWithoutHoldingExThrows(): void
    {
        $manager = new TableLockManager(self::TMP_DIR);

        try {
            $manager->deleteTableLock('users');
            Assert::fail('deleteTableLock without EX must throw');
        } catch (StorageException $e) {
            Assert::same($e->getErrorKey(), 'LOCK_ORDER_VIOLATION');
        }

        // Under SH only — still forbidden.
        $manager->withLocks(
            ['users' => 'sh'],
            null,
            static function () use ($manager): void {
                try {
                    $manager->deleteTableLock('users');
                    Assert::fail('deleteTableLock under SH must throw');
                } catch (StorageException $e) {
                    Assert::same($e->getErrorKey(), 'LOCK_ORDER_VIOLATION');
                }
            },
        );

        Assert::true(file_exists($this->tableLockPath('users')));
    }

    #[Test]
    public function deleteTableLockKeepsExclusionUntilFrameEnd(): void
    {
        // A waiter parked on the lock must NOT enter the critical section
        // when the holder deletes the lock file mid-frame: only the frame
        // exit releases it.
        $manager = new TableLockManager(self::TMP_DIR);

        $code = <<<'PHP'
            require $argv[1];
            $manager = new \AV\JsonProvider\Storage\TableLockManager(
                $argv[2],
                20.0,
            );
            $manager->withLocks(
                ['users' => 'ex'],
                null,
                static function (): void {
                    echo "entered\n";
                },
            );
            echo "done\n";
            PHP;

        $manager->withLocks(
            ['users' => 'ex'],
            null,
            function () use ($manager, $code): void {
                $child = $this->spawnPhp($code, [
                    \dirname(__DIR__, 2) . '/vendor/autoload.php',
                    self::TMP_DIR,
                ]);
                stream_set_blocking($child['pipes'][1], false);

                // Let the waiter park on the lock, then drop the lock file.
                usleep(150_000);
                $manager->deleteTableLock('users');
                usleep(300_000);

                $early = stream_get_contents($child['pipes'][1]);
                Assert::same($early === false ? '' : $early, '');

                $this->pendingChild = $child;
            },
        );

        // Frame closed — now the waiter must get in and finish.
        $child = $this->pendingChild;
        \assert($child !== null);
        $this->pendingChild = null;
        stream_set_blocking($child['pipes'][1], true);

        $stdout = $this->drainAndClose($child);
        Assert::string($stdout)->contains('entered');
        Assert::string($stdout)->contains('done');
    }

    #[Test]
    public function staleCachedHandleCannotBypassRecreatedLockFile(): void
    {
        // Regression for the two-EX-holders scenario: manager A cached a
        // handle to the old lock file inode; the table was dropped
        // (deleteTableLock) and re-created; a fresh process holds EX on the
        // NEW lock file. A's next acquisition must contend with the new
        // file — not silently succeed on the orphaned inode.
        $managerA = new TableLockManager(self::TMP_DIR, 0.2);

        // Populate A's handle cache.
        $managerA->withLocks(['users' => 'ex'], null, static fn (): int => 1);

        // Drop + recreate cycle.
        $managerA->withLocks(
            ['users' => 'ex'],
            null,
            static function () use ($managerA): void {
                $managerA->deleteTableLock('users');
            },
        );

        // A fresh holder takes EX on the newly created lock file.
        $holder = $this->spawnManagerHolder('users');

        try {
            try {
                $managerA->withLocks(
                    ['users' => 'ex'],
                    null,
                    static fn (): int => 1,
                );
                Assert::fail(
                    'stale cached handle must not bypass the new lock file',
                );
            } catch (StorageException $e) {
                Assert::same($e->getErrorKey(), 'LOCK_TIMEOUT');
            }
        } finally {
            $this->releaseHolder($holder);
        }

        // After the holder exits, A re-syncs onto the new file and works.
        $result = $managerA->withLocks(
            ['users' => 'ex'],
            null,
            static fn (): string => 'resynced',
        );
        Assert::same($result, 'resynced');
    }

    // -- helpers -----------------------------------------------------------

    private function tableLockPath(string $table): string
    {
        return self::TMP_DIR . '/.locks/table.' . $table . '.lock';
    }

    private function serviceLockPath(string $file): string
    {
        return self::TMP_DIR . '/.locks/svc.' . $file . '.lock';
    }

    private function dbLockPath(): string
    {
        return self::TMP_DIR . '/.locks/db.lock';
    }

    /**
     * Non-blocking flock probe through a fresh file descriptor: returns true
     * when the lock could be taken (i.e. nobody holds a conflicting lock).
     */
    private function probeFree(string $path, string $mode): bool
    {
        $dir = \dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $handle = fopen($path, 'c');
        \assert($handle !== false);

        $ok = flock(
            $handle,
            ($mode === 'ex' ? LOCK_EX : LOCK_SH) | LOCK_NB,
        );

        if ($ok) {
            flock($handle, LOCK_UN);
        }

        fclose($handle);

        return $ok;
    }

    /**
     * Spawns a child PHP process that flocks $path in the given mode and
     * holds the lock until released via releaseHolder() (or its death).
     *
     * @return array{proc: resource, pipes: array<int,resource>}
     */
    private function spawnFlockHolder(string $path, string $mode): array
    {
        $dir = \dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $code = <<<'PHP'
            $h = fopen($argv[1], 'c');
            if ($h === false || !flock(
                $h,
                $argv[2] === 'ex' ? LOCK_EX : LOCK_SH,
            )) {
                fwrite(STDERR, "flock failed\n");
                exit(1);
            }
            echo "locked\n";
            fflush(STDOUT);
            fgets(STDIN);
            PHP;

        $child = $this->spawnPhp($code, [$path, $mode]);
        $line = fgets($child['pipes'][1]);

        if ($line === false || !str_contains($line, 'locked')) {
            proc_terminate($child['proc'], 9);
            proc_close($child['proc']);
            Assert::fail('flock holder child failed to start');
        }

        return $child;
    }

    /**
     * Spawns a child that holds a table EX lock via TableLockManager itself
     * (fresh handle cache, current lock file) until released.
     *
     * @return array{proc: resource, pipes: array<int,resource>}
     */
    private function spawnManagerHolder(string $table): array
    {
        $code = <<<'PHP'
            require $argv[1];
            $manager = new \AV\JsonProvider\Storage\TableLockManager(
                $argv[2],
                20.0,
            );
            $manager->withLocks(
                [$argv[3] => 'ex'],
                null,
                static function (): void {
                    echo "locked\n";
                    fflush(STDOUT);
                    fgets(STDIN);
                },
            );
            PHP;

        $child = $this->spawnPhp($code, [
            \dirname(__DIR__, 2) . '/vendor/autoload.php',
            self::TMP_DIR,
            $table,
        ]);
        $line = fgets($child['pipes'][1]);

        if ($line === false || !str_contains($line, 'locked')) {
            proc_terminate($child['proc'], 9);
            proc_close($child['proc']);
            Assert::fail('manager holder child failed to start');
        }

        return $child;
    }

    /**
     * @param list<string> $args
     *
     * @return array{proc: resource, pipes: array<int,resource>}
     */
    private function spawnPhp(string $code, array $args): array
    {
        $cmd = array_merge([PHP_BINARY, '-r', $code, '--'], $args);
        $pipes = [];
        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        \assert(\is_resource($proc));

        return ['proc' => $proc, 'pipes' => $pipes];
    }

    /**
     * @param array{proc: resource, pipes: array<int,resource>} $child
     */
    private function releaseHolder(array $child): void
    {
        fclose($child['pipes'][0]);
        fclose($child['pipes'][1]);
        fclose($child['pipes'][2]);
        proc_close($child['proc']);
    }

    /**
     * Closes stdin (letting the child finish) and returns its full stdout.
     *
     * @param array{proc: resource, pipes: array<int,resource>} $child
     */
    private function drainAndClose(array $child): string
    {
        fclose($child['pipes'][0]);
        $stdout = stream_get_contents($child['pipes'][1]);
        fclose($child['pipes'][1]);
        fclose($child['pipes'][2]);
        proc_close($child['proc']);

        return $stdout === false ? '' : $stdout;
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $path,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);

            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($path);
    }
}
