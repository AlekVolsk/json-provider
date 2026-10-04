<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Support;

use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\ColumnTypes;
use AV\JsonProvider\Schema\ForeignKeyActionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\RelationTypeEnum;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;

/**
 * The lock-contention stand: a tree of ownership under a root table, read
 * by pollers in background processes while a writer touches the root.
 *
 * Schema: users (root, ROWS rows) → containers → sections → items →
 * comments by cascade, items and comments also point at their author by
 * setNull, sessions belong to users by cascade. Every relation is on `id`,
 * so no relation has an action on update. Children carry the container key
 * under a user index, the way a "load the whole container" query needs it.
 *
 * A poll loads one container the index way — a session by token, the
 * container by public id, its sections, items and comments by container
 * key, their authors by id list — so every step holds the shared lock of
 * its table (full scans take no locks and would not contend). A root
 * touch updates one user row; a leaf touch updates one session row, a
 * table of the same size with no children.
 *
 * Two identical databases are seeded: "busy" is where the contention runs,
 * "quiet" is the reference nobody else touches. Workers are separate PHP
 * processes that loop until the stop file appears or WORKER_LIFETIME runs
 * out, so a crashed benchmark never leaves them running for long.
 */
final class ConcurrencyFixture
{
    public const string DB_PREFIX = 'jp-bench-concurrency';

    public const int ROWS = 1000;

    public const int SECTIONS = 4;

    public const int ITEMS = 3;

    public const int COMMENTS = 2;

    public const int POLLERS = 4;

    public const int WRITER_PAUSE_US = 50_000;

    public const int WORKER_LIFETIME = 600;

    private const string WORKER = <<<'PHP'
        [, $autoload, $dbPath, $stopFile, $role, $seed] = $argv;
        require $autoload;
        $db = \AV\JsonProvider\JsonDataProvider::getInstance($dbPath);
        mt_srand((int)$seed);
        $fixture = \AV\JsonProvider\Tests\Support\ConcurrencyFixture::class;
        $deadline = microtime(true) + $fixture::WORKER_LIFETIME;
        $done = 0;
        while (!file_exists($stopFile) && microtime(true) < $deadline) {
            $fixture::work($db, $role);
            $done++;
        }
        file_put_contents($stopFile . '.' . $role . '.' . getmypid(), $done);
        PHP;

    /** @var list<array{process:resource, pipes:array<int,resource>}> */
    private static array $workers = [];

    private static bool $seeded = false;

    public static function busyPath(): string
    {
        return TempDir::root(self::DB_PREFIX) . '/busy';
    }

    public static function quietPath(): string
    {
        return TempDir::root(self::DB_PREFIX) . '/quiet';
    }

    public static function busy(): JsonDataProvider
    {
        self::seed();

        return JsonDataProvider::getInstance(self::busyPath());
    }

    public static function quiet(): JsonDataProvider
    {
        self::seed();

        return JsonDataProvider::getInstance(self::quietPath());
    }

    /**
     * Seeds both databases with the same rows, once per process.
     */
    public static function seed(): void
    {
        if (self::$seeded) {
            return;
        }

        self::$seeded = true;

        foreach ([self::busyPath(), self::quietPath()] as $path) {
            TempDir::remove($path);
            $db = JsonDataProvider::createDatabase($path);
            self::createTables($db);
            self::fill($db);
        }
    }

    /**
     * One step of a worker: a poll for a poller, a root touch and a pause
     * for a writer.
     */
    public static function work(JsonDataProvider $db, string $role): void
    {
        if ($role === 'poll') {
            self::poll($db, mt_rand(1, self::ROWS));

            return;
        }

        self::touchRoot($db, mt_rand(1, self::ROWS));
        usleep(self::WRITER_PAUSE_US);
    }

    /**
     * Loads one container the index way; returns the number of rows read.
     */
    public static function poll(JsonDataProvider $db, int $container): int
    {
        $rows = \count($db->table('sessions')
            ->where('token', '=', 'token' . $container)->selectAllByArray());
        $rows += \count($db->table('containers')
            ->where('publicId', '=', 'c' . $container)->selectAllByArray());
        $rows += \count($db->table('sections')
            ->where('containerId', '=', $container)->selectAllByArray());
        $items = $db->table('items')
            ->where('containerId', '=', $container)->selectAllByArray();
        $comments = $db->table('comments')
            ->where('containerId', '=', $container)->selectAllByArray();
        $authors = array_values(array_unique(array_filter(array_merge(
            array_column($items, 'authorId'),
            array_column($comments, 'authorId'),
        ), static fn (mixed $id): bool => \is_int($id))));
        $rows += \count($db->table('users')
            ->where('id', 'IN', $authors)->selectAllByArray());

        return $rows + \count($items) + \count($comments);
    }

    /**
     * Updates one root row: the "user signed in" touch.
     */
    public static function touchRoot(JsonDataProvider $db, int $user): void
    {
        $db->table('users')->where('id', '=', $user)
            ->updateByArray(['lastSeen' => (int)(microtime(true) * 1000)]);
    }

    /**
     * Updates one session row: a table of the root's size with no
     * children.
     */
    public static function touchLeaf(JsonDataProvider $db, int $session): void
    {
        $db->table('sessions')->where('id', '=', $session)
            ->updateByArray(['lastSeen' => (int)(microtime(true) * 1000)]);
    }

    /**
     * Starts background workers on a database: 'poll' or 'write'.
     */
    public static function start(string $dbPath, string $role, int $n): void
    {
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';

        for ($i = 0; $i < $n; $i++) {
            $process = proc_open(
                [
                    PHP_BINARY,
                    '-d',
                    'memory_limit=512M',
                    '-r',
                    self::WORKER,
                    $autoload,
                    $dbPath,
                    self::stopFile(),
                    $role,
                    (string)($i + 1),
                ],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );

            if (!\is_resource($process)) {
                throw new \RuntimeException('worker did not start');
            }

            self::$workers[] = ['process' => $process, 'pipes' => $pipes];
        }
    }

    /**
     * Stops every worker and returns how many steps each role made; a
     * worker that failed fails the caller with its output.
     *
     * @return array<string,int>
     */
    public static function stop(): array
    {
        touch(self::stopFile());
        $failures = [];

        foreach (self::$workers as $worker) {
            $output = stream_get_contents($worker['pipes'][1])
                . stream_get_contents($worker['pipes'][2]);

            foreach ($worker['pipes'] as $pipe) {
                fclose($pipe);
            }

            if (proc_close($worker['process']) !== 0) {
                $failures[] = $output;
            }
        }

        self::$workers = [];
        $done = [];

        $statsFiles = glob(self::stopFile() . '.*');

        foreach ($statsFiles === false ? [] : $statsFiles as $stats) {
            $role = explode('.', basename($stats))[1] ?? '';
            $done[$role] = ($done[$role] ?? 0)
                + (int)file_get_contents($stats);
            unlink($stats);
        }

        unlink(self::stopFile());

        if ($failures !== []) {
            throw new \RuntimeException(implode("\n", $failures));
        }

        return $done;
    }

    public static function drop(): void
    {
        TempDir::remove(TempDir::root(self::DB_PREFIX));
        self::$seeded = false;
    }

    private static function stopFile(): string
    {
        return TempDir::root(self::DB_PREFIX) . '/stop';
    }

    private static function createTables(JsonDataProvider $db): void
    {
        $db->createTable(TableSchema::create(
            name: 'users',
            columns: [
                'name'     => ColumnTypes::STRING,
                'lastSeen' => ColumnTypes::INT,
            ],
        ));
        $db->createTable(TableSchema::create(
            name: 'sessions',
            columns: [
                'userId'   => ColumnTypes::INT,
                'token'    => ColumnTypes::STRING,
                'lastSeen' => ColumnTypes::INT,
            ],
            uniqueConstraints: [new UniqueConstraint('uq_token', ['token'])],
            indexes: [self::index('idx_sessions_token', 'token')],
        ));
        $db->createTable(TableSchema::create(
            name: 'containers',
            columns: [
                'userId'   => ColumnTypes::INT,
                'publicId' => ColumnTypes::STRING,
                'title'    => ColumnTypes::STRING,
            ],
            indexes: [self::index('idx_containers_public', 'publicId')],
        ));
        $db->createTable(TableSchema::create(
            name: 'sections',
            columns: [
                'containerId' => ColumnTypes::INT,
                'title'       => ColumnTypes::STRING,
            ],
            indexes: [self::index('idx_sections_container', 'containerId')],
        ));
        $db->createTable(TableSchema::create(
            name: 'items',
            columns: [
                'sectionId'   => ColumnTypes::INT,
                'containerId' => ColumnTypes::INT,
                'authorId'    => ColumnTypes::INT_NULLABLE,
                'text'        => ColumnTypes::STRING,
            ],
            indexes: [self::index('idx_items_container', 'containerId')],
        ));
        $db->createTable(TableSchema::create(
            name: 'comments',
            columns: [
                'itemId'      => ColumnTypes::INT,
                'containerId' => ColumnTypes::INT,
                'authorId'    => ColumnTypes::INT_NULLABLE,
                'text'        => ColumnTypes::STRING,
            ],
            indexes: [self::index('idx_comments_container', 'containerId')],
        ));

        $cascade = ForeignKeyActionEnum::CASCADE;
        $setNull = ForeignKeyActionEnum::SET_NULL;

        foreach (
            [
                ['sessions', 'userId', 'users', $cascade],
                ['containers', 'userId', 'users', $cascade],
                ['sections', 'containerId', 'containers', $cascade],
                ['items', 'sectionId', 'sections', $cascade],
                ['items', 'authorId', 'users', $setNull],
                ['comments', 'itemId', 'items', $cascade],
                ['comments', 'authorId', 'users', $setNull],
            ] as [$from, $key, $to, $onDelete]
        ) {
            $db->addRelation(new RelationSchema(
                fromTable: $from,
                foreignKey: $key,
                toTable: $to,
                references: 'id',
                type: RelationTypeEnum::BELONGS_TO,
                onDelete: $onDelete,
            ));
        }
    }

    private static function fill(JsonDataProvider $db): void
    {
        $users = [];
        $sessions = [];
        $containers = [];
        $sections = [];
        $items = [];
        $comments = [];

        for ($u = 1; $u <= self::ROWS; $u++) {
            $users[] = ['id' => $u, 'name' => 'user' . $u, 'lastSeen' => 0];
            $sessions[] = [
                'id'       => $u,
                'userId'   => $u,
                'token'    => 'token' . $u,
                'lastSeen' => 0,
            ];
            $containers[] = [
                'id'       => $u,
                'userId'   => $u,
                'publicId' => 'c' . $u,
                'title'    => 'container ' . $u,
            ];

            for ($s = 0; $s < self::SECTIONS; $s++) {
                $section = \count($sections) + 1;
                $sections[] = [
                    'id'          => $section,
                    'containerId' => $u,
                    'title'       => 'section ' . $section,
                ];

                for ($i = 0; $i < self::ITEMS; $i++) {
                    $item = \count($items) + 1;
                    $items[] = [
                        'id'          => $item,
                        'sectionId'   => $section,
                        'containerId' => $u,
                        'authorId'    => ($item % self::ROWS) + 1,
                        'text'        => 'item text ' . $item,
                    ];

                    for ($c = 0; $c < self::COMMENTS; $c++) {
                        $comment = \count($comments) + 1;
                        $comments[] = [
                            'id'          => $comment,
                            'itemId'      => $item,
                            'containerId' => $u,
                            'authorId'    => ($comment % self::ROWS) + 1,
                            'text'        => 'comment text ' . $comment,
                        ];
                    }
                }
            }
        }

        $db->importRecords('users', $users);
        $db->importRecords('sessions', $sessions);
        $db->importRecords('containers', $containers);
        $db->importRecords('sections', $sections);
        $db->importRecords('items', $items);
        $db->importRecords('comments', $comments);
    }

    private static function index(string $name, string $column): IndexSchema
    {
        return new IndexSchema(
            $name,
            [new IndexFieldSchema($column, SortDirectionEnum::ASC)],
        );
    }
}
