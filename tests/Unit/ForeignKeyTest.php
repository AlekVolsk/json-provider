<?php

declare(strict_types=1);

namespace AV\JsonProvider\Tests\Unit;

use AV\JsonProvider\Exception\JsonProviderException;
use AV\JsonProvider\JsonDataProvider;
use AV\JsonProvider\Schema\ForeignKeyActionEnum;
use AV\JsonProvider\Schema\RelationSchema;
use AV\JsonProvider\Schema\RelationTypeEnum;
use AV\JsonProvider\Schema\TableSchema;
use AV\JsonProvider\Schema\UniqueConstraint;
use AV\JsonProvider\Tests\Support\TempDir;
use Testo\Assert;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Tests for the canonical FK direction (belongsTo: the declaring table is
 * the child; hasMany/hasOne: the target table is) and for the relation
 * DDL API: addRelation validation (columns, base types, referenced-column
 * uniqueness, onUpdate-on-PK, SET NULL nullability), canonical duplicate
 * rejection, dropRelation and relations().
 */
final class ForeignKeyTest
{
    private string $dbDir;

    private JsonDataProvider $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->removeDir(self::dbPathRoot());

        $this->dbDir = self::dbPathRoot() . '/' . uniqid('db', true);
        $this->db = JsonDataProvider::createDatabase($this->dbDir);

        $this->db->createTable(TableSchema::create(
            name: 'users',
            uniqueConstraints: [
                new UniqueConstraint('u_login', ['login']),
            ],
            columns: [
                'id'    => 'int',
                'login' => 'string',
                'note'  => 'string',
            ],
        ));
        $this->db->createTable(TableSchema::create(
            name: 'posts',
            columns: [
                'id'     => 'int',
                'userId' => 'int|null',
                'title'  => 'string',
            ],
        ));
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->removeDir(self::dbPathRoot());
    }

    #[Test]
    public function hasManyCascadesIntoItsToTableChild(): void
    {
        $this->db->addRelation(new RelationSchema(
            fromTable: 'users',
            foreignKey: 'userId',
            toTable: 'posts',
            references: 'id',
            type: RelationTypeEnum::HAS_MANY,
            onDelete: ForeignKeyActionEnum::CASCADE,
        ));

        $userId = $this->db->insert('users', [
            'login' => 'a',
            'note'  => '',
        ]);
        $this->db->insert('posts', ['userId' => $userId, 'title' => 't1']);
        $this->db->insert('posts', ['userId' => $userId, 'title' => 't2']);
        $this->db->insert('posts', ['userId' => null, 'title' => 'free']);

        $this->db->table('users')->deleteById($userId);

        $titles = array_column(
            $this->db->table('posts')->selectAllByArray(),
            'title',
        );
        Assert::same(
            $titles,
            ['free'],
            'hasMany must cascade into the TO table (the child), '
                . 'null FKs untouched',
        );
    }

    #[Test]
    public function belongsToCascadesIntoItsFromTableChild(): void
    {
        $this->db->addRelation(new RelationSchema(
            fromTable: 'posts',
            foreignKey: 'userId',
            toTable: 'users',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::CASCADE,
        ));

        $userId = $this->db->insert('users', [
            'login' => 'a',
            'note'  => '',
        ]);
        $this->db->insert('posts', ['userId' => $userId, 'title' => 't1']);

        $this->db->table('users')->deleteById($userId);

        Assert::same($this->db->table('posts')->count(), 0);
    }

    #[Test]
    public function hasManyDeclaredBackwardsIsRejectedLoudly(): void
    {
        try {
            $this->db->addRelation(new RelationSchema(
                fromTable: 'posts',
                foreignKey: 'userId',
                toTable: 'users',
                references: 'id',
                type: RelationTypeEnum::HAS_MANY,
                onDelete: ForeignKeyActionEnum::CASCADE,
            ));
            Assert::fail(
                'hasMany with from=child resolves the FK column into the '
                    . 'users table, which has no userId',
            );
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'RelationColumnNotFound');
        }
    }

    #[Test]
    public function mirroredNotationOfTheSameEdgeIsADuplicate(): void
    {
        $this->db->addRelation(new RelationSchema(
            fromTable: 'posts',
            foreignKey: 'userId',
            toTable: 'users',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::CASCADE,
        ));

        try {
            $this->db->addRelation(new RelationSchema(
                fromTable: 'users',
                foreignKey: 'userId',
                toTable: 'posts',
                references: 'id',
                type: RelationTypeEnum::HAS_MANY,
                onDelete: ForeignKeyActionEnum::RESTRICT,
            ));
            Assert::fail(
                'the mirrored hasMany describes the same canonical edge '
                    . 'and must be rejected',
            );
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'RelationAlreadyExists');
        }

        Assert::count($this->db->relations(), 1);
    }

    #[Test]
    public function addRelationIsActiveImmediately(): void
    {
        $userId = $this->db->insert('users', [
            'login' => 'a',
            'note'  => '',
        ]);
        $this->db->insert('posts', ['userId' => $userId, 'title' => 't']);

        $this->db->addRelation(new RelationSchema(
            fromTable: 'posts',
            foreignKey: 'userId',
            toTable: 'users',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::CASCADE,
        ));

        $this->db->table('users')->deleteById($userId);

        Assert::same(
            $this->db->table('posts')->count(),
            0,
            'the relation must enforce without any restart or reload',
        );
    }

    #[Test]
    public function dropRelationRemovesTheEdgeAndSecondDropFails(): void
    {
        $this->db->addRelation(new RelationSchema(
            fromTable: 'posts',
            foreignKey: 'userId',
            toTable: 'users',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::CASCADE,
        ));

        $this->db->dropRelation('posts', 'userId', 'users');

        Assert::count($this->db->relations(), 0);

        $userId = $this->db->insert('users', [
            'login' => 'a',
            'note'  => '',
        ]);
        $this->db->insert('posts', ['userId' => $userId, 'title' => 't']);
        $this->db->table('users')->deleteById($userId);
        Assert::same(
            $this->db->table('posts')->count(),
            1,
            'a dropped relation must stop cascading',
        );

        try {
            $this->db->dropRelation('posts', 'userId', 'users');
            Assert::fail('the relation is gone; the drop must fail');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'RelationNotFound');
        }
    }

    #[Test]
    public function unknownColumnIsRejected(): void
    {
        try {
            $this->db->addRelation(new RelationSchema(
                fromTable: 'posts',
                foreignKey: 'authorId',
                toTable: 'users',
                references: 'id',
                type: RelationTypeEnum::BELONGS_TO,
                onDelete: ForeignKeyActionEnum::CASCADE,
            ));
            Assert::fail('posts has no authorId column');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'RelationColumnNotFound');
        }
    }

    #[Test]
    public function unknownTableIsRejected(): void
    {
        try {
            $this->db->addRelation(new RelationSchema(
                fromTable: 'comments',
                foreignKey: 'userId',
                toTable: 'users',
                references: 'id',
                type: RelationTypeEnum::BELONGS_TO,
                onDelete: ForeignKeyActionEnum::CASCADE,
            ));
            Assert::fail('the comments table does not exist');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'TableNotFound');
        }
    }

    #[Test]
    public function nonUniqueReferencedColumnIsRejected(): void
    {
        try {
            $this->db->addRelation(new RelationSchema(
                fromTable: 'posts',
                foreignKey: 'title',
                toTable: 'users',
                references: 'note',
                type: RelationTypeEnum::BELONGS_TO,
                onDelete: ForeignKeyActionEnum::CASCADE,
            ));
            Assert::fail('users.note is neither the PK nor unique');
        } catch (JsonProviderException $e) {
            Assert::same(
                $e->getErrorKey(),
                'RelationReferencesNotUnique',
            );
        }
    }

    #[Test]
    public function typeMismatchIsRejectedAndNullSuffixIgnored(): void
    {
        try {
            $this->db->addRelation(new RelationSchema(
                fromTable: 'posts',
                foreignKey: 'title',
                toTable: 'users',
                references: 'id',
                type: RelationTypeEnum::BELONGS_TO,
                onDelete: ForeignKeyActionEnum::CASCADE,
            ));
            Assert::fail('string FK into an int PK must be rejected');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'RelationTypeMismatch');
        }

        $this->db->addRelation(new RelationSchema(
            fromTable: 'posts',
            foreignKey: 'userId',
            toTable: 'users',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::CASCADE,
        ));
        Assert::count($this->db->relations(), 1);
    }

    #[Test]
    public function onUpdateOnThePkIsUndeclarable(): void
    {
        try {
            $this->db->addRelation(new RelationSchema(
                fromTable: 'posts',
                foreignKey: 'userId',
                toTable: 'users',
                references: 'id',
                type: RelationTypeEnum::BELONGS_TO,
                onUpdate: ForeignKeyActionEnum::CASCADE,
            ));
            Assert::fail('id never changes; onUpdate on it could not fire');
        } catch (JsonProviderException $e) {
            Assert::same($e->getErrorKey(), 'RelationOnUpdateOnPk');
        }
    }

    #[Test]
    public function onUpdateOnANonPkUniqueColumnReallyCascades(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'sessions',
            columns: ['id' => 'int', 'userLogin' => 'string'],
        ));
        $this->db->addRelation(new RelationSchema(
            fromTable: 'sessions',
            foreignKey: 'userLogin',
            toTable: 'users',
            references: 'login',
            type: RelationTypeEnum::BELONGS_TO,
            onUpdate: ForeignKeyActionEnum::CASCADE,
        ));

        $this->db->insert('users', ['login' => 'old', 'note' => '']);
        $this->db->insert('sessions', ['userLogin' => 'old']);
        $this->db->insert('sessions', ['userLogin' => 'other']);

        $this->db->table('users')
            ->where('login', '=', 'old')
            ->updateByArray(['login' => 'new']);

        $logins = array_column(
            $this->db->table('sessions')
                ->orderBy('id', 'asc')
                ->selectAllByArray(),
            'userLogin',
        );
        Assert::same(
            $logins,
            ['new', 'other'],
            'the parent value change must cascade into the child FK',
        );
    }

    #[Test]
    public function setNullOnNonNullableFkIsUndeclarable(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'strictPosts',
            columns: ['id' => 'int', 'userId' => 'int'],
        ));

        try {
            $this->db->addRelation(new RelationSchema(
                fromTable: 'strictPosts',
                foreignKey: 'userId',
                toTable: 'users',
                references: 'id',
                type: RelationTypeEnum::BELONGS_TO,
                onDelete: ForeignKeyActionEnum::SET_NULL,
            ));
            Assert::fail('SET NULL on a non-nullable FK cannot work');
        } catch (JsonProviderException $e) {
            Assert::same(
                $e->getErrorKey(),
                'ForeignKeySetNullNotNullable',
            );
        }
    }

    #[Test]
    public function relationsFilterByTableAndListAll(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'likes',
            columns: ['id' => 'int', 'postId' => 'int|null'],
        ));
        $this->db->addRelation(new RelationSchema(
            fromTable: 'posts',
            foreignKey: 'userId',
            toTable: 'users',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::CASCADE,
        ));
        $this->db->addRelation(new RelationSchema(
            fromTable: 'likes',
            foreignKey: 'postId',
            toTable: 'posts',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::CASCADE,
        ));

        Assert::count($this->db->relations(), 2);
        Assert::count($this->db->relations('users'), 1);
        Assert::count($this->db->relations('posts'), 2);
        Assert::count($this->db->relations('likes'), 1);
        Assert::count($this->db->relations('unrelated'), 0);
    }

    #[Test]
    public function droppingTheUniqueGroundOfARelationIsRefused(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'sessions',
            columns: ['id' => 'int', 'userLogin' => 'string'],
        ));
        $this->db->addRelation(new RelationSchema(
            fromTable: 'sessions',
            foreignKey: 'userLogin',
            toTable: 'users',
            references: 'login',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::CASCADE,
        ));

        try {
            $this->db->dropUniqueConstraint('users', 'u_login');
            Assert::fail(
                'dropping the uniqueness ground would let a cascade '
                    . 'delete the children of a living duplicate parent',
            );
        } catch (JsonProviderException $e) {
            Assert::same(
                $e->getErrorKey(),
                'RelationReferencesNotUnique',
            );
        }

        $this->db->dropRelation('sessions', 'userLogin', 'users');
        $this->db->dropUniqueConstraint('users', 'u_login');
        Assert::count(
            $this->db->relations(),
            0,
            'without the relation the constraint drops normally',
        );
    }

    #[Test]
    public function migrateCannotDropARelationColumn(): void
    {
        $this->db->addRelation(new RelationSchema(
            fromTable: 'posts',
            foreignKey: 'userId',
            toTable: 'users',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::SET_NULL,
        ));

        try {
            $this->db->migrateColumns(TableSchema::create(
                name: 'posts',
                columns: ['id' => 'int', 'title' => 'string'],
            ));
            Assert::fail(
                'dropping the FK column of a live setNull relation would '
                    . 'leave every parent delete failing',
            );
        } catch (JsonProviderException $e) {
            Assert::same(
                $e->getErrorKey(),
                'MigrateFieldUnknownColumnRelation',
            );
        }

        Assert::same(
            $this->db->columnNames('posts'),
            ['id', 'userId', 'title'],
        );
    }

    #[Test]
    public function transitiveCascadeReachesGrandchildren(): void
    {
        $this->db->createTable(TableSchema::create(
            name: 'likes',
            columns: ['id' => 'int', 'postId' => 'int|null'],
        ));
        $this->db->addRelation(new RelationSchema(
            fromTable: 'posts',
            foreignKey: 'userId',
            toTable: 'users',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::CASCADE,
        ));
        $this->db->addRelation(new RelationSchema(
            fromTable: 'likes',
            foreignKey: 'postId',
            toTable: 'posts',
            references: 'id',
            type: RelationTypeEnum::BELONGS_TO,
            onDelete: ForeignKeyActionEnum::CASCADE,
        ));

        $userId = $this->db->insert('users', [
            'login' => 'a',
            'note'  => '',
        ]);
        $postId = $this->db->insert('posts', [
            'userId' => $userId,
            'title'  => 't',
        ]);
        $this->db->insert('likes', ['postId' => $postId]);
        $this->db->insert('likes', ['postId' => null]);

        $this->db->table('users')->deleteById($userId);

        Assert::same($this->db->table('posts')->count(), 0);
        Assert::same(
            $this->db->table('likes')->count(),
            1,
            'the like of the cascaded post must go; the free like stays',
        );
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

    private static function dbPathRoot(): string
    {
        return TempDir::root('jp-fk-tests');
    }
}
