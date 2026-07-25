<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\Storage\TableLockManager;
use AV\JsonProvider\Tests\Support\TempDir;
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
    /** @var null|array{proc: resource, pipes: array<int,resource>} */
    private array | null $pendingChild = null;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::tmpDirRoot());
        mkdir(self::tmpDirRoot(), 0755, true);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::tmpDirRoot());
    }

    #[Test]
    public function withLocksRunsCallbackAndReturnsItsValue(): void
    {
        $manager = new TableLockManager(self::tmpDirRoot());

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
        $manager = new TableLockManager(self::tmpDirRoot());

        Assert::false(is_dir(self::tmpDirRoot() . '/.locks'));

        $manager->withLocks(['users' => 'ex'], null, static fn (): int => 1);

        Assert::true(is_dir(self::tmpDirRoot() . '/.locks'));
    }

    #[Test]
    public function lockFilesArePersistentAndEmptyAfterRelease(): void
    {
        $manager = new TableLockManager(self::tmpDirRoot());

        $manager->withLocks(['users' => 'ex'], 'sh', static fn (): int => 1);

        $tableLock = $this->tableLockPath('users');
        $dbLock = self::tmpDirRoot() . '/.locks/db.lock';

        Assert::true(file_exists($tableLock));
        Assert::true(file_exists($dbLock));
        Assert::same(filesize($tableLock), 0);
        Assert::same(filesize($dbLock), 0);
    }

    #[Test]
    public function locksAreReleasedAfterCallbackReturns(): void
    {
        $manager = new TableLockManager(self::tmpDirRoot());

        $manager->withLocks(['users' => 'ex'], 'ex', static fn (): int => 1);

        Assert::true($this->probeFree($this->tableLockPath('users'), 'ex'));
        Assert::true($this->probeFree($this->dbLockPath(), 'ex'));
    }

    #[Test]
    public function locksAreReleasedWhenCallbackThrows(): void
    {
        $manager = new TableLockManager(self::tmpDirRoot());

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
        $manager = new TableLockManager(self::tmpDirRoot());

        Expect::exception(\InvalidArgumentException::class)
            ->withMessageContaining('Lock mode');

        $manager->withLocks(
            ['users' => 'shared'],
            null,
            static fn (): int => 1,
        );
    }

    #[Test]
    public function isHeldReflectsTableLocksOnly(): void
    {
        $manager = new TableLockManager(self::tmpDirRoot());

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
        $manager = new TableLockManager(self::tmpDirRoot());

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
        $manager = new TableLockManager(self::tmpDirRoot());

        $manager->withLocks([], 'sh', static function () use ($manager): void {
            Assert::true($manager->isDatabaseHeld('sh'));
            Assert::false($manager->isDatabaseHeld('ex'));
        });
    }

    #[Test]
    public function nestedSubsetPassesAndInnerExitKeepsOuterLockHeld(): void
    {
        $manager = new TableLockManager(self::tmpDirRoot());

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
        $manager = new TableLockManager(self::tmpDirRoot());

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
        $manager = new TableLockManager(self::tmpDirRoot());

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

    #[Test]
    public function tableUpgradeShToExThrows(): void
    {
        $manager = new TableLockManager(self::tmpDirRoot());

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
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'LockOrderTableUpgrade');
            Assert::string($e->getMessage())->contains('upgrade');
        }

        Assert::false($manager->isHeld('users', 'sh'));
    }

    #[Test]
    public function freshTableOutsideNonEmptyHeldSetThrows(): void
    {
        $manager = new TableLockManager(self::tmpDirRoot());

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
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'LockOrderTableOutsideHeldSet');
            Assert::string($e->getMessage())
                ->contains('outside the held lock set');
        }

        Assert::false($manager->isHeld('users', 'sh'));
        Assert::false($manager->isHeld('logs', 'sh'));
    }

    #[Test]
    public function databaseLockOnTopOfHeldTablesThrows(): void
    {
        $manager = new TableLockManager(self::tmpDirRoot());

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
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'LockOrderDatabaseAfterTables');
        }

        Assert::false($manager->isHeld('users', 'sh'));
    }

    #[Test]
    public function databaseUpgradeShToExThrows(): void
    {
        $manager = new TableLockManager(self::tmpDirRoot());

        try {
            $manager->withLocks(
                [],
                'sh',
                static fn (): mixed => $manager->withDatabase(
                    static fn (): int => 1,
                ),
            );
            Assert::fail('db upgrade must throw');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'LockOrderDatabaseUpgrade');
        }

        Assert::false($manager->isDatabaseHeld('sh'));
    }

    #[Test]
    public function partialAcquisitionFailureReleasesEverything(): void
    {
        $holder = $this->spawnFlockHolder(
            $this->tableLockPath('users'),
            'ex',
        );

        try {
            $manager = new TableLockManager(self::tmpDirRoot(), 0.1);

            try {
                $manager->withLocks(
                    ['users' => 'ex'],
                    'sh',
                    static fn (): int => 1,
                );
                Assert::fail('must time out');
            } catch (JsonProviderException $e) {
                Assert::same($e->getErrorKey(), 'LockTimeoutExclusiveTable');
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
        $holder = $this->spawnFlockHolder($this->tableLockPath('bbb'), 'ex');

        try {
            $manager = new TableLockManager(self::tmpDirRoot(), 0.1);

            try {
                $manager->withLocks(
                    ['bbb' => 'ex', 'aaa' => 'ex'],
                    null,
                    static fn (): int => 1,
                );
                Assert::fail('must time out');
            } catch (JsonProviderException $e) {
                Assert::same($e->getErrorKey(), 'LockTimeoutExclusiveTable');
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
        $manager = new TableLockManager(self::tmpDirRoot());

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
        $manager = new TableLockManager(self::tmpDirRoot());

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
        $manager = new TableLockManager(self::tmpDirRoot());

        foreach (['', '.', '..', 'a/b', '../escape'] as $bad) {
            try {
                $manager->withLocks(
                    [$bad => 'ex'],
                    null,
                    static fn (): int => 1,
                );
                Assert::fail('name "' . $bad . '" must be rejected');
            } catch (JsonProviderException $e) {
                Assert::same($e->getErrorKey(), 'InvalidFileName');
            }
        }

        try {
            $manager->withServiceFile('../db', static fn (): int => 1);
            Assert::fail('service name must be rejected');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'InvalidFileName');
        }

        Assert::false(file_exists(self::tmpDirRoot() . '/escape.table.lock'));
        Assert::false(file_exists(\dirname(self::tmpDirRoot()) . '/db.lock'));
    }

    #[Test]
    public function lockFileNamespacesDoNotCollide(): void
    {
        $manager = new TableLockManager(self::tmpDirRoot());

        $manager->withServiceFile('db', static fn (): int => 1);
        $manager->withServiceFile('users.table', static fn (): int => 1);

        Assert::true(
            file_exists(self::tmpDirRoot() . '/.locks/svc.db.lock'),
        );
        Assert::true(
            file_exists(self::tmpDirRoot() . '/.locks/svc.users.table.lock'),
        );
        Assert::false(file_exists(self::tmpDirRoot() . '/.locks/db.lock'));
        Assert::false(file_exists($this->tableLockPath('users')));

        $holder = $this->spawnFlockHolder(
            $this->serviceLockPath('db'),
            'ex',
        );

        try {
            $quick = new TableLockManager(self::tmpDirRoot(), 0.5);
            $result = $quick->withDatabase(static fn (): string => 'db-ok');
            Assert::same($result, 'db-ok');
        } finally {
            $this->releaseHolder($holder);
        }
    }

    #[Test]
    public function serviceFileLockRunsAndReleases(): void
    {
        $manager = new TableLockManager(self::tmpDirRoot());

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
        $manager = new TableLockManager(self::tmpDirRoot());

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
        $manager = new TableLockManager(self::tmpDirRoot());

        try {
            $manager->withServiceFile(
                'meta.json',
                static fn (): mixed => $manager->withServiceFile(
                    'information_schema.json',
                    static fn (): int => 1,
                ),
            );
            Assert::fail('cross-leaf nesting must throw');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'LockOrderServiceFileNested');
        }

        Assert::true($this->probeFree(
            $this->serviceLockPath('meta.json'),
            'ex',
        ));
    }

    #[Test]
    public function tableLockUnderServiceFileLockThrows(): void
    {
        $manager = new TableLockManager(self::tmpDirRoot());

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
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'LockOrderAfterServiceFile');
        }
    }

    #[Test]
    public function serviceFileUnderTableLockPasses(): void
    {
        $manager = new TableLockManager(self::tmpDirRoot());

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

    #[Test]
    public function contendedLockTimesOutWithInjectedCeiling(): void
    {
        $holder = $this->spawnFlockHolder(
            $this->tableLockPath('users'),
            'ex',
        );

        try {
            $manager = new TableLockManager(self::tmpDirRoot(), 0.15);
            $start = microtime(true);

            try {
                $manager->withLocks(
                    ['users' => 'ex'],
                    null,
                    static fn (): int => 1,
                );
                Assert::fail('contended lock must time out');
            } catch (JsonProviderException $e) {
                Assert::same($e->getErrorKey(), 'LockTimeoutExclusiveTable');
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

    #[Test]
    public function exclusiveBlocksBothExclusiveAndShared(): void
    {
        $holder = $this->spawnFlockHolder(
            $this->tableLockPath('users'),
            'ex',
        );

        try {
            $manager = new TableLockManager(self::tmpDirRoot(), 0.1);

            try {
                $manager->withLocks(
                    ['users' => 'ex'],
                    null,
                    static fn (): int => 1,
                );
                Assert::fail('EX vs EX must block');
            } catch (JsonProviderException $e) {
                Assert::same($e->getErrorKey(), 'LockTimeoutExclusiveTable');
            }

            try {
                $manager->withLocks(
                    ['users' => 'sh'],
                    null,
                    static fn (): int => 1,
                );
                Assert::fail('EX vs SH must block');
            } catch (JsonProviderException $e) {
                Assert::same($e->getErrorKey(), 'LockTimeoutSharedTable');
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
            $manager = new TableLockManager(self::tmpDirRoot(), 0.1);

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
            } catch (JsonProviderException $e) {
                Assert::same($e->getErrorKey(), 'LockTimeoutExclusiveTable');
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
            $manager = new TableLockManager(self::tmpDirRoot(), 0.1);

            $result = $manager->withLocks(
                ['users' => 'ex'],
                'sh',
                static fn (): string => 'dml',
            );
            Assert::same($result, 'dml');

            try {
                $manager->withDatabase(static fn (): int => 1);
                Assert::fail('db SH vs db EX must block');
            } catch (JsonProviderException $e) {
                Assert::same($e->getErrorKey(), 'LockTimeoutExclusiveDatabase');
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

        $manager = new TableLockManager(self::tmpDirRoot(), 5.0);
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
        mkdir(self::tmpDirRoot() . '/.locks', 0755, true);
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
            self::tmpDirRoot(),
        ]);

        try {
            $deadline = microtime(true) + 10.0;

            while ($this->probeFree($this->tableLockPath('a'), 'sh')) {
                if (microtime(true) > $deadline) {
                    Assert::fail('child never acquired table "a"');
                }

                usleep(2_000);
            }

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
            $manager = new TableLockManager(self::tmpDirRoot(), 0.1);

            try {
                $manager->withServiceFile('meta.json', static fn (): int => 1);
                Assert::fail('service-file lock must exclude');
            } catch (JsonProviderException $e) {
                Assert::same(
                    $e->getErrorKey(),
                    'LockTimeoutExclusiveServiceFile',
                );
            }
        } finally {
            $this->releaseHolder($holder);
        }
    }

    #[Test]
    public function deleteTableLockRemovesFileAndAllowsReacquisition(): void
    {
        $manager = new TableLockManager(self::tmpDirRoot());

        $manager->withLocks(
            ['users' => 'ex'],
            null,
            static function () use ($manager): void {
                $manager->deleteTableLock('users');
            },
        );

        Assert::false(file_exists($this->tableLockPath('users')));

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
        $manager = new TableLockManager(self::tmpDirRoot());

        try {
            $manager->deleteTableLock('users');
            Assert::fail('deleteTableLock without EX must throw');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'LockOrderDeleteRequiresExclusive');
        }

        $manager->withLocks(
            ['users' => 'sh'],
            null,
            static function () use ($manager): void {
                try {
                    $manager->deleteTableLock('users');
                    Assert::fail('deleteTableLock under SH must throw');
                } catch (JsonProviderException $e) {
                    Assert::same(
                        $e->getErrorKey(),
                        'LockOrderDeleteRequiresExclusive',
                    );
                }
            },
        );

        Assert::true(file_exists($this->tableLockPath('users')));
    }

    #[Test]
    public function deleteTableLockKeepsExclusionUntilFrameEnd(): void
    {
        $manager = new TableLockManager(self::tmpDirRoot());

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
                    self::tmpDirRoot(),
                ]);
                stream_set_blocking($child['pipes'][1], false);

                usleep(150_000);
                $manager->deleteTableLock('users');
                usleep(300_000);

                $early = stream_get_contents($child['pipes'][1]);
                Assert::same($early === false ? '' : $early, '');

                $this->pendingChild = $child;
            },
        );

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
        $managerA = new TableLockManager(self::tmpDirRoot(), 0.2);

        $managerA->withLocks(['users' => 'ex'], null, static fn (): int => 1);

        $managerA->withLocks(
            ['users' => 'ex'],
            null,
            static function () use ($managerA): void {
                $managerA->deleteTableLock('users');
            },
        );

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
            } catch (JsonProviderException $e) {
                Assert::same($e->getErrorKey(), 'LockTimeoutExclusiveTable');
            }
        } finally {
            $this->releaseHolder($holder);
        }

        $result = $managerA->withLocks(
            ['users' => 'ex'],
            null,
            static fn (): string => 'resynced',
        );
        Assert::same($result, 'resynced');
    }

    private function tableLockPath(string $table): string
    {
        return self::tmpDirRoot() . '/.locks/table.' . $table . '.lock';
    }

    private function serviceLockPath(string $file): string
    {
        return self::tmpDirRoot() . '/.locks/svc.' . $file . '.lock';
    }

    private function dbLockPath(): string
    {
        return self::tmpDirRoot() . '/.locks/db.lock';
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
            self::tmpDirRoot(),
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

    private static function tmpDirRoot(): string
    {
        return TempDir::root('jp-lock-tests');
    }
}
