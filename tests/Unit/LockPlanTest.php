<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Relations\FkEngine;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\ForeignKeyActionEnum;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\RelationTypeEnum;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
use AV\JsonProvider\Tests\Support\EngineAccess;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Which tables a write locks. A delete follows onDelete edges, an update
 * onUpdate edges; a set-null patch is a value change, so a patched child
 * goes on along its onUpdate edges. No plan locks a parent: no FK action
 * reads a parent row, and a parent's own delete or key update locks the
 * child table, so it serializes with a child insert there.
 *
 * Schema: users (login unique) ← profiles.userLogin (setNull on delete,
 * cascade on update) ← devices.profileLogin (cascade on update); users.id
 * ← posts (cascade) ← comments (cascade), comments.authorId → users
 * (setNull), audits → users (restrict).
 */
final class LockPlanTest
{
    private const ForeignKeyActionEnum CASCADE
        = ForeignKeyActionEnum::CASCADE;
    private const ForeignKeyActionEnum SET_NULL
        = ForeignKeyActionEnum::SET_NULL;
    private const ForeignKeyActionEnum RESTRICT
        = ForeignKeyActionEnum::RESTRICT;

    private string $root;

    private string $dbDir;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->root = TempDir::root('jp-lock-plan');
        $this->dbDir = $this->root . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);
        $this->buildSchema();
    }

    #[AfterTest]
    public function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    #[Test]
    public function deleteFollowsOnDeleteEdgesAndPatchedChildrenOnUpdate(): void
    {
        Assert::same($this->plan('delete', 'users'), [
            'audits'   => 'sh',
            'comments' => 'ex',
            'devices'  => 'ex',
            'posts'    => 'ex',
            'profiles' => 'ex',
            'users'    => 'ex',
        ]);
    }

    /**
     * Relations on `id` carry no action on update, so an update of the
     * root locks only what its key relations reach — not the cascade tree
     * under it.
     */
    #[Test]
    public function updateFollowsOnlyOnUpdateEdges(): void
    {
        Assert::same($this->plan('update', 'users'), [
            'devices'  => 'ex',
            'profiles' => 'ex',
            'users'    => 'ex',
        ]);
        Assert::same($this->plan('update', 'posts'), ['posts' => 'ex']);
    }

    #[Test]
    public function noPlanLocksParents(): void
    {
        Assert::same($this->plan('insert', 'comments'), ['comments' => 'ex']);
        Assert::same($this->plan('delete', 'comments'), ['comments' => 'ex']);
        Assert::same($this->plan('update', 'comments'), ['comments' => 'ex']);
        Assert::same($this->plan('delete', 'devices'), ['devices' => 'ex']);
    }

    #[Test]
    public function cyclesTerminate(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'nodes',
            columns: ['parentId' => ColumnTypes::INT_NULLABLE],
        ));
        $this->relate('nodes', 'parentId', 'nodes', self::CASCADE);
        $this->db->createTable(TableSchema::create(
            name: 'ring_a',
            columns: ['bId' => ColumnTypes::INT_NULLABLE],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'ring_b',
            columns: ['aId' => ColumnTypes::INT_NULLABLE],
        ));
        $this->relate('ring_a', 'bId', 'ring_b', self::CASCADE);
        $this->relate('ring_b', 'aId', 'ring_a', self::CASCADE);

        Assert::same($this->plan('delete', 'nodes'), ['nodes' => 'ex']);
        Assert::same(
            $this->plan('delete', 'ring_a'),
            ['ring_a' => 'ex', 'ring_b' => 'ex'],
        );
    }

    /**
     * The root touch goes through while another process holds the tables
     * of the cascade tree under the root exclusively.
     */
    #[Test]
    public function rootUpdateDoesNotWaitForTheTreeUnderIt(): void
    {
        $holder = $this->hold(['posts', 'comments', 'audits']);

        try {
            $this->db->table('users')->where('id', '=', 1)
                ->updateByArray(['name' => 'touched']);
        } finally {
            $this->release($holder);
        }

        Assert::same(
            $this->db->table('users')->where('id', '=', 1)
                ->selectAllByArray()[0]['name'],
            'touched',
        );
    }

    #[Test]
    public function insertDoesNotWaitForParents(): void
    {
        $holder = $this->hold(['users', 'posts']);

        try {
            $this->db->insert('comments', ['postId' => 1, 'authorId' => 2]);
        } finally {
            $this->release($holder);
        }

        Assert::same($this->db->count('comments'), 6);
    }

    /**
     * Every table a delete deletes in, patches or probes is locked: the
     * delete finishes only after another process lets go of it.
     */
    #[Test]
    public function deleteWaitsForEveryTableItTouches(): void
    {
        $tables = ['posts', 'comments', 'profiles', 'devices', 'audits'];

        foreach ($tables as $user => $table) {
            $holder = $this->hold([$table], 300_000);
            $this->db->table('users')->deleteById($user + 1);
            $finished = microtime(true);

            Assert::true(
                $finished >= $this->release($holder),
                'the delete did not wait for ' . $table,
            );
        }
    }

    /**
     * A key update that cascades into children locks them, so it
     * serializes with a child insert there as the parent lock used to.
     */
    #[Test]
    public function keyUpdateWaitsForTheChildrenItCascadesInto(): void
    {
        $holder = $this->hold(['profiles'], 300_000);
        $this->db->table('users')->where('id', '=', 1)
            ->updateByArray(['login' => 'renamed']);
        $finished = microtime(true);

        Assert::true($finished >= $this->release($holder));
        Assert::same(
            array_column(
                $this->db->table('devices')->where('id', '=', 1)
                    ->selectAllByArray(),
                'profileLogin',
            ),
            ['renamed'],
        );
    }

    /**
     * @return array<string,string>
     */
    private function plan(string $operation, string $table): array
    {
        $engine = EngineAccess::call(
            EngineAccess::part($this->db, 'writer'),
            'fkEngine',
        );
        $registry = EngineAccess::context($this->db)->schema;
        Assert::true($engine instanceof FkEngine);
        $schema = $registry->getTable($table);

        $plan = match ($operation) {
            'insert' => $engine->insertLockPlan($schema),
            'delete' => $engine->deleteLockPlan($schema),
            default  => $engine->updateLockPlan($schema),
        };
        ksort($plan);

        return $plan;
    }

    private function buildSchema(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'users',
            columns: [
                'login' => ColumnTypes::STRING,
                'name'  => ColumnTypes::STRING,
            ],
            uniqueConstraints: [new UniqueConstraint('u_login', ['login'])],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'profiles',
            columns: ['userLogin' => ColumnTypes::STRING_NULLABLE],
            uniqueConstraints: [
                new UniqueConstraint('u_profile_login', ['userLogin']),
            ],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'devices',
            columns: ['profileLogin' => ColumnTypes::STRING_NULLABLE],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'posts',
            columns: ['userId' => ColumnTypes::INT],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'comments',
            columns: [
                'postId'   => ColumnTypes::INT,
                'authorId' => ColumnTypes::INT_NULLABLE,
            ],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'audits',
            columns: ['userId' => ColumnTypes::INT],
        ));

        $this->db->addRelation(new RelationSchema(
            fromTable: 'profiles',
            foreignKey: 'userLogin',
            toTable: 'users',
            references: 'login',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::SET_NULL,
            onUpdate: ForeignKeyActionEnum::CASCADE,
        ));
        $this->db->addRelation(new RelationSchema(
            fromTable: 'devices',
            foreignKey: 'profileLogin',
            toTable: 'profiles',
            references: 'userLogin',
            type: RelationTypeEnum::BELONGS_TO,
            onUpdate: ForeignKeyActionEnum::CASCADE,
        ));
        $this->relate('posts', 'userId', 'users', self::CASCADE);
        $this->relate('comments', 'postId', 'posts', self::CASCADE);
        $this->relate('comments', 'authorId', 'users', self::SET_NULL);
        $this->relate('audits', 'userId', 'users', self::RESTRICT);

        for ($u = 1; $u <= 5; $u++) {
            $this->db->insert('users', ['login' => 'u' . $u, 'name' => 'n']);
            $this->db->insert('profiles', ['userLogin' => 'u' . $u]);
            $this->db->insert('devices', ['profileLogin' => 'u' . $u]);
            $this->db->insert('posts', ['userId' => $u]);
            $this->db->insert('comments', ['postId' => $u, 'authorId' => $u]);
        }
    }

    private function relate(
        string $from,
        string $key,
        string $to,
        ForeignKeyActionEnum $onDelete,
    ): void {
        $this->db->addRelation(new RelationSchema(
            fromTable: $from,
            foreignKey: $key,
            toTable: $to,
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: $onDelete,
        ));
    }

    /**
     * Starts a process holding the tables' locks exclusively — until
     * release() or, with $forMicroseconds, for that long — and returns
     * once it holds them.
     *
     * @param list<string> $tables
     *
     * @return array{proc: resource, pipes: array<int,resource>}
     */
    private function hold(array $tables, int $forMicroseconds = 0): array
    {
        $code = <<<'PHP'
            $handles = [];
            foreach (array_slice($argv, 2) as $path) {
                $h = fopen($path, 'c');
                if ($h === false || !flock($h, LOCK_EX)) {
                    fwrite(STDERR, "flock failed\n");
                    exit(1);
                }
                $handles[] = $h;
            }
            echo "locked\n";
            fflush(STDOUT);
            if ((int)$argv[1] > 0) {
                usleep((int)$argv[1]);
            } else {
                fgets(STDIN);
            }
            $released = microtime(true);
            foreach ($handles as $h) {
                flock($h, LOCK_UN);
            }
            echo $released, "\n";
            PHP;

        $paths = [];

        foreach ($tables as $table) {
            $paths[] = $this->dbDir . '/.locks/table.' . $table . '.lock';
        }

        $pipes = [];
        $proc = proc_open(
            array_merge(
                [PHP_BINARY, '-r', $code, '--', (string)$forMicroseconds],
                $paths,
            ),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        Assert::true(\is_resource($proc));

        $line = fgets($pipes[1]);
        Assert::true(\is_string($line) && str_contains($line, 'locked'));

        return ['proc' => $proc, 'pipes' => $pipes];
    }

    /**
     * Lets the holder go and returns when it released the locks.
     *
     * @param array{proc: resource, pipes: array<int,resource>} $holder
     */
    private function release(array $holder): float
    {
        fclose($holder['pipes'][0]);
        $released = (float)stream_get_contents($holder['pipes'][1]);
        fclose($holder['pipes'][1]);
        fclose($holder['pipes'][2]);
        proc_close($holder['proc']);

        return $released;
    }
}
