<?php

declare(strict_types=1);

namespace Phoenix\Tests\Database\QueryBuilder;

use Phoenix\Database\Adapter\CassandraAdapter;
use Phoenix\Database\Element\Column;
use Phoenix\Database\Element\ColumnSettings;
use Phoenix\Database\Element\Index;
use Phoenix\Database\Element\MigrationTable;
use Phoenix\Database\Element\MigrationView;
use Phoenix\Database\QueryBuilder\CassandraQueryBuilder;
use Phoenix\Exception\InvalidArgumentValueException;
use Phoenix\Tests\Helpers\Adapter\CassandraCleanupAdapter;
use Phoenix\Tests\Helpers\Cassandra\CassandraSessionFactory;
use PHPUnit\Framework\TestCase;

final class CassandraQueryBuilderTest extends TestCase
{
    private CassandraAdapter $adapter;

    private CassandraQueryBuilder $queryBuilder;

    protected function setUp(): void
    {
        if (!extension_loaded('cassandra')) {
            $this->markTestSkipped('Extension "cassandra" is not loaded.');
        }
        $keyspace = CassandraSessionFactory::keyspace();
        (new CassandraCleanupAdapter($keyspace))->cleanupDatabase();

        $this->adapter = new CassandraAdapter(CassandraSessionFactory::create($keyspace), $keyspace);
        $this->queryBuilder = $this->adapter->getQueryBuilder();
    }

    public function testSimpleCreate(): void
    {
        $table = new MigrationTable('simple', 'id');
        $table->addColumn('id', Column::TYPE_UUID);
        $table->addColumn('title', Column::TYPE_STRING);
        $table->create();

        $this->assertSame(
            ['CREATE TABLE "simple" ("id" uuid,"title" text,PRIMARY KEY ("id"));'],
            $this->queryBuilder->createTable($table)
        );
    }

    public function testCompositePartitionKeyAndClusteringOrder(): void
    {
        $table = new MigrationTable('events', ['(tenant_id, day)', 'created_at DESC', 'id']);
        $table->addColumn('tenant_id', Column::TYPE_UUID);
        $table->addColumn('day', Column::TYPE_DATE);
        $table->addColumn('created_at', Column::TYPE_DATETIME);
        $table->addColumn('id', Column::TYPE_TIMEUUID);
        $table->create();

        $this->assertSame(
            ['CREATE TABLE "events" ("tenant_id" uuid,"day" date,"created_at" timestamp,"id" timeuuid,'
                . 'PRIMARY KEY (("tenant_id","day"),"created_at","id")) '
                . 'WITH CLUSTERING ORDER BY ("created_at" DESC,"id" ASC);'],
            $this->queryBuilder->createTable($table)
        );
    }

    public function testAllTypes(): void
    {
        $table = new MigrationTable('all_types', 'id');
        $table->addColumn('id', Column::TYPE_UUID);
        $table->addColumn('c_string', Column::TYPE_STRING);
        $table->addColumn('c_text', Column::TYPE_TEXT);
        $table->addColumn('c_json', Column::TYPE_JSON);
        $table->addColumn('c_ascii', Column::TYPE_ASCII);
        $table->addColumn('c_tinyint', Column::TYPE_TINY_INTEGER);
        $table->addColumn('c_smallint', Column::TYPE_SMALL_INTEGER);
        $table->addColumn('c_integer', Column::TYPE_INTEGER);
        $table->addColumn('c_bigint', Column::TYPE_BIG_INTEGER);
        $table->addColumn('c_varint', Column::TYPE_VARINT);
        $table->addColumn('c_counter', Column::TYPE_COUNTER);
        $table->addColumn('c_decimal', Column::TYPE_DECIMAL);
        $table->addColumn('c_float', Column::TYPE_FLOAT);
        $table->addColumn('c_double', Column::TYPE_DOUBLE);
        $table->addColumn('c_boolean', Column::TYPE_BOOLEAN);
        $table->addColumn('c_blob', Column::TYPE_BLOB);
        $table->addColumn('c_date', Column::TYPE_DATE);
        $table->addColumn('c_time', Column::TYPE_TIME);
        $table->addColumn('c_datetime', Column::TYPE_DATETIME);
        $table->addColumn('c_duration', Column::TYPE_DURATION);
        $table->addColumn('c_uuid', Column::TYPE_UUID);
        $table->addColumn('c_timeuuid', Column::TYPE_TIMEUUID);
        $table->addColumn('c_inet', Column::TYPE_INET);
        $table->addColumn('c_enum', Column::TYPE_ENUM, [ColumnSettings::SETTING_VALUES => ['a', 'b']]);
        $table->addColumn('c_set', Column::TYPE_SET, [ColumnSettings::SETTING_VALUES => ['a', 'b']]);
        $table->addColumn('c_typed_set', Column::TYPE_SET, [ColumnSettings::SETTING_VALUES => ['int']]);
        $table->addColumn('c_list', Column::TYPE_LIST, [ColumnSettings::SETTING_VALUES => ['int']]);
        $table->addColumn('c_map', Column::TYPE_MAP, [ColumnSettings::SETTING_VALUES => ['text', 'bigint']]);
        $table->addColumn('c_tuple', Column::TYPE_TUPLE, [ColumnSettings::SETTING_VALUES => ['text', 'int']]);
        $table->create();

        $this->assertSame(
            ['CREATE TABLE "all_types" ("id" uuid,"c_string" text,"c_text" text,"c_json" text,"c_ascii" ascii,'
                . '"c_tinyint" tinyint,"c_smallint" smallint,"c_integer" int,"c_bigint" bigint,"c_varint" varint,'
                . '"c_counter" counter,"c_decimal" decimal,"c_float" float,"c_double" double,"c_boolean" boolean,'
                . '"c_blob" blob,"c_date" date,"c_time" time,"c_datetime" timestamp,"c_duration" duration,'
                . '"c_uuid" uuid,"c_timeuuid" timeuuid,"c_inet" inet,"c_enum" text,"c_set" set<text>,'
                . '"c_typed_set" set<int>,"c_list" list<int>,"c_map" map<text,bigint>,"c_tuple" tuple<text,int>,'
                . 'PRIMARY KEY ("id"));'],
            $this->queryBuilder->createTable($table)
        );
    }

    public function testCreateTableWithCommentAndIndex(): void
    {
        $table = new MigrationTable('with_index', 'id');
        $table->addColumn('id', Column::TYPE_UUID);
        $table->addColumn('title', Column::TYPE_STRING);
        $table->setComment("it's commented");
        $table->addIndex('title', Index::TYPE_NORMAL, Index::METHOD_DEFAULT, 'with_index_title');
        $table->create();

        $this->assertSame([
            'CREATE TABLE "with_index" ("id" uuid,"title" text,PRIMARY KEY ("id")) WITH comment = \'it\'\'s commented\';',
            'CREATE INDEX IF NOT EXISTS "with_index_title" ON "with_index" ("title");',
        ], $this->queryBuilder->createTable($table));
    }

    public function testDropAndTruncateTable(): void
    {
        $table = new MigrationTable('to_drop');
        $table->drop();
        $this->assertSame(['DROP TABLE "to_drop";'], $this->queryBuilder->dropTable($table));
        $this->assertSame(['TRUNCATE TABLE "to_drop";'], $this->queryBuilder->truncateTable($table));
    }

    public function testAlterTable(): void
    {
        $table = new MigrationTable('altered');
        $table->addColumn('nickname', Column::TYPE_STRING);
        $table->changeColumn('title', 'title', Column::TYPE_BLOB);
        $table->dropColumn('obsolete');
        $table->renameColumn('id', 'identifier');
        $table->dropIndexByName('altered_title');
        $table->setComment('new comment');
        $table->save();

        $this->assertSame([
            'DROP INDEX IF EXISTS "altered_title";',
            'ALTER TABLE "altered" RENAME "id" TO "identifier";',
            'ALTER TABLE "altered" ALTER "title" TYPE blob;',
            'ALTER TABLE "altered" DROP ("obsolete");',
            'ALTER TABLE "altered" ADD ("nickname" text);',
            'ALTER TABLE "altered" WITH comment = \'new comment\';',
        ], $this->queryBuilder->alterTable($table));
    }

    public function testViewsAreMaterializedViews(): void
    {
        $view = new MigrationView('users_by_name');
        $view->sql('SELECT * FROM "users" WHERE "name" IS NOT NULL PRIMARY KEY (("name"),"id")');

        $expectedCreate = 'CREATE MATERIALIZED VIEW "users_by_name" AS SELECT * FROM "users" WHERE "name" IS NOT NULL PRIMARY KEY (("name"),"id");';
        $expectedDrop = 'DROP MATERIALIZED VIEW IF EXISTS "users_by_name";';

        $this->assertSame([$expectedCreate], $this->queryBuilder->createView($view));
        $this->assertSame([$expectedDrop], $this->queryBuilder->dropView($view));
        $this->assertSame([$expectedDrop, $expectedCreate], $this->queryBuilder->replaceView($view));
    }

    public function testRenameTableIsNotSupported(): void
    {
        $table = new MigrationTable('old_name');
        $table->rename('new_name');

        $this->expectException(InvalidArgumentValueException::class);
        $this->expectExceptionMessage('Cassandra does not support renaming of tables');
        $this->queryBuilder->renameTable($table);
    }

    public function testAutoincrementIsNotSupported(): void
    {
        $table = new MigrationTable('with_autoincrement', true);
        $table->addColumn('title', Column::TYPE_STRING);
        $table->create();

        $this->expectException(InvalidArgumentValueException::class);
        $this->expectExceptionMessage('Cassandra has no autoincrement');
        $this->queryBuilder->createTable($table);
    }

    public function testDefaultValueIsNotSupported(): void
    {
        $table = new MigrationTable('with_default', 'id');
        $table->addColumn('id', Column::TYPE_UUID);
        $table->addColumn('title', Column::TYPE_STRING, [ColumnSettings::SETTING_DEFAULT => 'x']);
        $table->create();

        $this->expectException(InvalidArgumentValueException::class);
        $this->expectExceptionMessage('Cassandra has no column default values');
        $this->queryBuilder->createTable($table);
    }

    public function testForeignKeysAreNotSupported(): void
    {
        $table = new MigrationTable('with_fk', 'id');
        $table->addColumn('id', Column::TYPE_UUID);
        $table->addColumn('other_id', Column::TYPE_UUID);
        $table->addForeignKey('other_id', 'other');
        $table->create();

        $this->expectException(InvalidArgumentValueException::class);
        $this->expectExceptionMessage('Cassandra does not support foreign keys');
        $this->queryBuilder->createTable($table);
    }

    public function testUniqueConstraintsAreNotSupported(): void
    {
        $table = new MigrationTable('with_unique', 'id');
        $table->addColumn('id', Column::TYPE_UUID);
        $table->addColumn('title', Column::TYPE_STRING);
        $table->addUniqueConstraint('title', 'with_unique_title');
        $table->create();

        $this->expectException(InvalidArgumentValueException::class);
        $this->expectExceptionMessage('Cassandra does not support unique constraints');
        $this->queryBuilder->createTable($table);
    }

    public function testUniqueIndexIsNotSupported(): void
    {
        $table = new MigrationTable('with_unique_index', 'id');
        $table->addColumn('id', Column::TYPE_UUID);
        $table->addColumn('title', Column::TYPE_STRING);
        $table->addIndex('title', Index::TYPE_UNIQUE, Index::METHOD_DEFAULT, 'with_unique_index_title');
        $table->create();

        $this->expectException(InvalidArgumentValueException::class);
        $this->expectExceptionMessage('Cassandra does not support unique indexes');
        $this->queryBuilder->createTable($table);
    }

    public function testMultiColumnIndexIsNotSupported(): void
    {
        $table = new MigrationTable('multi_index', 'id');
        $table->addColumn('id', Column::TYPE_UUID);
        $table->addColumn('title', Column::TYPE_STRING);
        $table->addColumn('alias', Column::TYPE_STRING);
        $table->addIndex(['title', 'alias'], Index::TYPE_NORMAL, Index::METHOD_DEFAULT, 'multi_index_title_alias');
        $table->create();

        $this->expectException(InvalidArgumentValueException::class);
        $this->expectExceptionMessage('has to be created on exactly one column, 2 given');
        $this->queryBuilder->createTable($table);
    }

    public function testTableWithoutPrimaryKeyIsNotSupported(): void
    {
        $table = new MigrationTable('no_primary_key', false);
        $table->addColumn('title', Column::TYPE_STRING);
        $table->create();

        $this->expectException(InvalidArgumentValueException::class);
        $this->expectExceptionMessage('has no primary key');
        $this->queryBuilder->createTable($table);
    }

    public function testChangingPrimaryKeyIsNotSupported(): void
    {
        $table = new MigrationTable('altered_primary_key');
        $table->addPrimary('title');
        $table->save();

        $this->expectException(InvalidArgumentValueException::class);
        $this->expectExceptionMessage('does not support changing the primary key');
        $this->queryBuilder->alterTable($table);
    }
}
