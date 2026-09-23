<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Cache\InMemoryCache;
use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Query\SortDirectionEnum;
use AV\JsonProvider\Schema\IndexFieldSchema;
use AV\JsonProvider\Schema\IndexSchema;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Tests\Support\CacheKeys;
use AV\JsonProvider\Tests\Support\FsList;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * On-disk names of tables and indexes are lower case while the schema keeps
 * names as given, so the layout is the same on case-sensitive and
 * case-insensitive file systems. Names differing only in case share files
 * and are therefore duplicates — rejected on DDL and on schema load — and
 * repair never reaches a live table through a differently cased orphan
 * directory.
 */
final class PhysicalNameTest
{
    private string $dbDir = '';

    private InMemoryCache $cache;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->dbDir = self::root() . '/' . uniqid('db', true);
        $this->cache = new InMemoryCache();
        $this->db = JsonDataProvider::createDatabase(
            $this->dbDir,
            $this->cache,
        );
        $this->db->createTable(TableSchema::create(
            name: 'Orders',
            columns: ['n' => 'string'],
            indexes: [self::index('byN', 'n')],
        ));

        foreach (['b', 'a'] as $n) {
            $this->db->insert('Orders', ['n' => $n]);
        }
    }

    #[AfterTest]
    public function tearDown(): void
    {
        TempDir::remove(self::root());
    }

    #[Test]
    public function onDiskNamesAreLowerCase(): void
    {
        Assert::same(
            FsList::entries($this->dbDir . '/orders'),
            ['byn.index.ndjson', 'orders.ndjson', 'pk.index.ndjson'],
        );
        Assert::true(is_file($this->dbDir . '/.locks/table.orders.lock'));
        Assert::same($this->db->tableNames(), ['Orders']);
        Assert::same(
            $this->db->table('Orders')->orderBy('n')->selectColumn('n'),
            ['a', 'b'],
        );
    }

    #[Test]
    public function namesDifferingOnlyInCaseAreDuplicates(): void
    {
        $this->expectErrorKey(
            'TableAlreadyExists',
            fn () => $this->db->createTable(TableSchema::create(
                name: 'orders',
                columns: ['x' => 'int'],
            )),
        );
        Assert::same($this->db->table('Orders')->count(), 2);

        $this->expectErrorKey(
            'IndexAlreadyExists',
            fn () => $this->db->addIndex('Orders', self::index('BYN', 'n')),
        );
        $this->expectErrorKey(
            'IndexAlreadyExists',
            fn () => $this->db->createTable(TableSchema::create(
                name: 't',
                columns: ['x' => 'int'],
                indexes: [self::index('ix', 'x'), self::index('IX', 'x')],
            )),
        );
    }

    #[Test]
    public function renameChangingOnlyCaseIsRejected(): void
    {
        $this->expectErrorKey(
            'TableAlreadyExists',
            fn () => $this->db->renameTable('Orders', 'orders'),
        );
        Assert::same($this->db->tableNames(), ['Orders']);
    }

    #[Test]
    public function renameBackupRestoreAndDropFollowTheLowerCaseLayout(): void
    {
        $this->db->renameTable('Orders', 'Sales');
        Assert::true(is_file($this->dbDir . '/sales/sales.ndjson'));
        Assert::false(is_dir($this->dbDir . '/orders'));

        $archive = $this->db->backup(self::root() . '/' . uniqid() . '.tar.gz');
        $this->db->insert('Sales', ['n' => 'c']);
        $this->db->restore($archive);
        Assert::same(
            $this->db->table('Sales')->orderBy('n')->selectColumn('n'),
            ['a', 'b'],
        );
        Assert::false($this->db->validate()->hasErrors());

        $this->db->dropTable('Sales');
        Assert::false(is_dir($this->dbDir . '/sales'));
    }

    #[Test]
    public function schemaWithCaseClashingTablesDoesNotLoad(): void
    {
        $path = $this->dbDir . '/information_schema.json';
        $schema = json_decode((string)file_get_contents($path), true);
        Assert::array($schema);
        $tables = $schema['tables'] ?? null;
        Assert::array($tables);
        $tables['orders'] = $tables['Orders'];
        $schema['tables'] = $tables;
        file_put_contents($path, json_encode($schema));

        $this->expectErrorKey(
            'SchemaTableNamesClash',
            fn () => $this->db->tableNames(),
        );
    }

    #[Test]
    public function repairLeavesUpperCaseOrphanDirectoryAndLiveTable(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'users',
            columns: ['x' => 'int'],
        ));
        mkdir($this->dbDir . '/Users');
        touch($this->dbDir . '/Users/leftover.txt');

        $this->db->repair();

        Assert::true(is_file($this->dbDir . '/Users/leftover.txt'));
        Assert::true(is_file($this->dbDir . '/users/users.ndjson'));
        Assert::same($this->db->table('users')->count(), 0);
    }

    #[Test]
    public function cacheEntryOfMixedCaseTableIsTaggedWithItsDataFile(): void
    {
        $this->db->table('Orders')->selectAllByArray();

        $key = CacheKeys::current($this->dbDir, 'Orders');

        Assert::false(str_ends_with($key, '-nofile'));
        Assert::notNull($this->cache->get($key));
    }

    private function expectErrorKey(string $key, callable $call): void
    {
        try {
            $call();
            Assert::fail('expected ' . $key);
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), $key);
        }
    }

    private static function index(string $name, string $field): IndexSchema
    {
        return new IndexSchema($name, [
            new IndexFieldSchema($field, SortDirectionEnum::ASC),
        ]);
    }

    private static function root(): string
    {
        return TempDir::root('jp-physical-name-tests');
    }
}
