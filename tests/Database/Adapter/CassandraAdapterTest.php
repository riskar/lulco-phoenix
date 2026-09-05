<?php

declare(strict_types=1);

namespace Phoenix\Tests\Database\Adapter;

use DateTime;
use InvalidArgumentException;
use PDO;
use Phoenix\Database\Adapter\CassandraAdapter;
use Phoenix\Database\Adapter\CassandraStatement;
use Phoenix\Database\Element\Column;
use Phoenix\Database\Element\ColumnSettings;
use Phoenix\Database\Element\Index;
use Phoenix\Database\Element\MigrationTable;
use Phoenix\Database\Element\Structure;
use Phoenix\Database\Element\Table;
use Phoenix\Database\QueryBuilder\CassandraQueryBuilder;
use Phoenix\Database\QueryBuilder\QueryBuilderInterface;
use Phoenix\Exception\DatabaseQueryExecuteException;
use Phoenix\Tests\Helpers\Adapter\CassandraCleanupAdapter;
use Phoenix\Tests\Helpers\Cassandra\CassandraSessionFactory;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class CassandraAdapterTest extends TestCase
{
    private CassandraAdapter $adapter;

    private string $keyspace;

    protected function setUp(): void
    {
        if (!extension_loaded('cassandra')) {
            $this->markTestSkipped('Extension "cassandra" is not loaded.');
        }
        $this->keyspace = CassandraSessionFactory::keyspace();
        (new CassandraCleanupAdapter($this->keyspace))->cleanupDatabase();
        $this->adapter = new CassandraAdapter(CassandraSessionFactory::create($this->keyspace), $this->keyspace);
    }

    public function testGetQueryBuilder(): void
    {
        $this->assertInstanceOf(QueryBuilderInterface::class, $this->adapter->getQueryBuilder());
        $this->assertInstanceOf(CassandraQueryBuilder::class, $this->adapter->getQueryBuilder());
    }

    public function testGetKeyspace(): void
    {
        $this->assertSame($this->keyspace, $this->adapter->getKeyspace());
    }

    public function testForeignKeyQueriesAreEmpty(): void
    {
        $this->assertSame('', $this->adapter->buildCheckForeignKeysQuery());
        $this->assertSame('', $this->adapter->buildDoNotCheckForeignKeysQuery());
    }

    public function testCharsetAndCollation(): void
    {
        $this->assertNull($this->adapter->getCharset());
        $this->assertNull($this->adapter->getCollation());
        $this->adapter->setCharset('utf8');
        $this->adapter->setCollation('utf8_general_ci');
        $this->assertSame('utf8', $this->adapter->getCharset());
        $this->assertSame('utf8_general_ci', $this->adapter->getCollation());
    }

    public function testTransactionsAreNoop(): void
    {
        $this->assertTrue($this->adapter->startTransaction());
        $this->assertTrue($this->adapter->commit());
        $this->assertTrue($this->adapter->rollback());
    }

    public function testGetEmptyStructureAndUpdate(): void
    {
        $structure = $this->adapter->getStructure();
        $this->assertInstanceOf(Structure::class, $structure);
        $this->assertEmpty($structure->getTables());
        $this->assertNull($structure->getTable('structure_test'));

        $migrationTable = $this->createTable('structure_test');
        $structure->update($migrationTable);
        $this->assertCount(1, $structure->getTables());

        $updatedStructure = $this->adapter->getStructure();
        $this->assertCount(1, $updatedStructure->getTables());
        $this->assertInstanceOf(Table::class, $updatedStructure->getTable('structure_test'));
    }

    public function testStructureOfCreatedTable(): void
    {
        $table = new MigrationTable('events', ['(tenant_id, day)', 'created_at DESC', 'id']);
        $table->addColumn('tenant_id', Column::TYPE_UUID);
        $table->addColumn('day', Column::TYPE_DATE);
        $table->addColumn('created_at', Column::TYPE_DATETIME);
        $table->addColumn('id', Column::TYPE_TIMEUUID);
        $table->addColumn('title', Column::TYPE_STRING);
        $table->addColumn('tags', Column::TYPE_SET, [ColumnSettings::SETTING_VALUES => ['text']]);
        $table->addColumn('meta', Column::TYPE_MAP, [ColumnSettings::SETTING_VALUES => ['text', 'int']]);
        $table->setComment('all the events');
        $table->addIndex('title', Index::TYPE_NORMAL, Index::METHOD_DEFAULT, 'events_title');
        $table->create();
        $this->runQueries($this->adapter->getQueryBuilder()->createTable($table));

        $loadedTable = $this->adapter->getStructure()->getTable('events');
        $this->assertInstanceOf(Table::class, $loadedTable);
        $this->assertSame('all the events', $loadedTable->getComment());
        // the composite partition key and the clustering order survive the round trip
        $this->assertSame(['(tenant_id, day)', 'created_at DESC', 'id'], $loadedTable->getPrimary());

        $this->assertSame(Column::TYPE_UUID, $loadedTable->getColumn('tenant_id')->getType());
        $this->assertSame(Column::TYPE_TIMEUUID, $loadedTable->getColumn('id')->getType());
        $this->assertSame(Column::TYPE_SET, $loadedTable->getColumn('tags')->getType());
        $this->assertSame(['text'], $loadedTable->getColumn('tags')->getSettings()->getValues());
        $this->assertSame(Column::TYPE_MAP, $loadedTable->getColumn('meta')->getType());
        $this->assertSame(['text', 'int'], $loadedTable->getColumn('meta')->getSettings()->getValues());

        // primary key columns are never nullable, regular ones always are
        $this->assertFalse($loadedTable->getColumn('tenant_id')->getSettings()->allowNull());
        $this->assertTrue($loadedTable->getColumn('title')->getSettings()->allowNull());

        $this->assertInstanceOf(Index::class, $loadedTable->getIndex('events_title'));
    }

    public function testInsertAndFetch(): void
    {
        $this->createTypedTable();
        $data = [
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'title' => 'Alice',
            'score' => 987654321012,
            'ratio' => 0.25,
            'active' => true,
            'ip' => '10.0.0.1',
            'payload' => 'raw-bytes',
            'day' => '2024-05-06',
            'created_at' => new DateTime('2024-05-06 10:11:12'),
            'tags' => ['a', 'b'],
            'scores' => [1, 2, 3],
            'meta' => ['x' => 1, 'y' => 2],
        ];
        $this->adapter->insert('typed', $data);

        $row = $this->adapter->fetch('typed');
        $this->assertIsArray($row);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $row['id']);
        $this->assertSame('Alice', $row['title']);
        $this->assertSame('987654321012', (string)$row['score']);
        $this->assertSame(0.25, $row['ratio']);
        $this->assertTrue((bool)$row['active']);
        $this->assertSame('10.0.0.1', $row['ip']);
        $this->assertSame('raw-bytes', $row['payload']);
        $this->assertSame('2024-05-06', $row['day']);
        $this->assertSame('2024-05-06 10:11:12', $row['created_at']);
        $this->assertSame(['a', 'b'], $row['tags']);
        $this->assertSame([1, 2, 3], $row['scores']);
        $this->assertSame(['x' => 1, 'y' => 2], $row['meta']);
    }

    public function testInsertMultipleRowsUsesBatch(): void
    {
        $this->createTypedTable();
        $statement = $this->adapter->buildInsertQuery('typed', [
            ['id' => '550e8400-e29b-41d4-a716-446655440001', 'title' => 'first'],
            ['id' => '550e8400-e29b-41d4-a716-446655440002', 'title' => 'second'],
        ]);
        $this->assertStringStartsWith('BEGIN BATCH ', $statement->getQueryString());
        $this->assertStringEndsWith(' APPLY BATCH;', $statement->getQueryString());

        $this->adapter->execute($statement);
        $this->assertCount(2, $this->adapter->fetchAll('typed'));
    }

    public function testUpdateAndDelete(): void
    {
        $this->createTypedTable();
        $id = '550e8400-e29b-41d4-a716-446655440000';
        $this->adapter->insert('typed', ['id' => $id, 'title' => 'before']);

        $this->assertTrue($this->adapter->update('typed', ['title' => 'after'], ['id' => $id]));
        $this->assertSame('after', $this->adapter->fetch('typed', ['title'], ['id' => $id])['title']);

        $this->assertTrue($this->adapter->delete('typed', ['id' => $id]));
        $this->assertNull($this->adapter->fetch('typed', ['*'], ['id' => $id]));
    }

    public function testFetchAllWithLimitAndOrder(): void
    {
        $this->createTypedTable();
        foreach (['c', 'a', 'b'] as $index => $title) {
            $this->adapter->insert('typed', ['id' => sprintf('550e8400-e29b-41d4-a716-44665544000%d', $index), 'title' => $title]);
        }

        // title is not a clustering column, so ordering happens in PHP
        $titles = array_column($this->adapter->fetchAll('typed', ['title'], [], null, ['title']), 'title');
        $this->assertSame(['a', 'b', 'c'], $titles);

        $descending = array_column($this->adapter->fetchAll('typed', ['title'], [], null, ['title' => 'DESC']), 'title');
        $this->assertSame(['c', 'b', 'a'], $descending);

        // the limit is applied after the PHP side sorting
        $limited = array_column($this->adapter->fetchAll('typed', ['title'], [], '2', ['title']), 'title');
        $this->assertSame(['a', 'b'], $limited);
    }

    public function testFetchWithInCondition(): void
    {
        $this->createTypedTable();
        $ids = ['550e8400-e29b-41d4-a716-446655440001', '550e8400-e29b-41d4-a716-446655440002'];
        foreach ($ids as $index => $id) {
            $this->adapter->insert('typed', ['id' => $id, 'title' => 'title_' . $index]);
        }
        $this->adapter->insert('typed', ['id' => '550e8400-e29b-41d4-a716-446655440003', 'title' => 'other']);

        $statement = $this->adapter->buildDeleteQuery('typed', ['id' => $ids]);
        $this->assertStringContainsString('IN (:where_0_id, :where_1_id)', $statement->getQueryString());

        $rows = $this->adapter->fetchAll('typed', ['title'], ['id' => $ids]);
        $this->assertCount(2, $rows);
    }

    public function testSelect(): void
    {
        $this->createTypedTable();
        $this->adapter->insert('typed', ['id' => '550e8400-e29b-41d4-a716-446655440000', 'title' => 'Alice']);
        $this->assertSame([['title' => 'Alice']], $this->adapter->select('SELECT "title" FROM "typed"'));
    }

    public function testSelectRefusesNonSelectQuery(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only select query can be executed in select method');
        $this->adapter->select('DROP TABLE "typed"');
    }

    public function testQueryReturnsCassandraStatement(): void
    {
        $this->createTable('statement_test');
        $statement = $this->adapter->query('SELECT "title" FROM "statement_test"');
        $this->assertInstanceOf(CassandraStatement::class, $statement);
        $this->assertSame('SELECT "title" FROM "statement_test"', $statement->getQueryString());
        $this->assertSame([], $statement->fetchAll());
        $this->assertNull($statement->fetch());
    }

    public function testFailingQueryThrows(): void
    {
        $this->expectException(DatabaseQueryExecuteException::class);
        $this->adapter->query('SELECT * FROM "table_which_does_not_exist"');
    }

    public function testExecuteRefusesPdoStatement(): void
    {
        $pdoStatement = (new PDO('sqlite::memory:'))->prepare('SELECT 1');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only CassandraStatement can be executed');
        $this->adapter->execute($pdoStatement);
    }

    public function testNullConditionIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('CQL cannot compare a column to NULL');
        $this->adapter->buildDeleteQuery('typed', ['title' => null]);
    }

    public function testNotEqualOperatorIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('CQL does not support the "!=" operator');
        $this->adapter->buildDeleteQuery('typed', ['title !=' => 'x']);
    }

    public function testInsertReturnsNoGeneratedId(): void
    {
        $this->createTypedTable();
        $this->assertNull($this->adapter->insert('typed', ['id' => '550e8400-e29b-41d4-a716-446655440000', 'title' => 'x']));
    }

    private function createTable(string $name): MigrationTable
    {
        $table = new MigrationTable($name, 'id');
        $table->addColumn('id', Column::TYPE_UUID);
        $table->addColumn('title', Column::TYPE_STRING);
        $table->create();
        $this->runQueries($this->adapter->getQueryBuilder()->createTable($table));
        return $table;
    }

    private function createTypedTable(): void
    {
        $table = new MigrationTable('typed', 'id');
        $table->addColumn('id', Column::TYPE_UUID);
        $table->addColumn('title', Column::TYPE_STRING);
        $table->addColumn('score', Column::TYPE_BIG_INTEGER);
        $table->addColumn('ratio', Column::TYPE_FLOAT);
        $table->addColumn('active', Column::TYPE_BOOLEAN);
        $table->addColumn('ip', Column::TYPE_INET);
        $table->addColumn('payload', Column::TYPE_BLOB);
        $table->addColumn('day', Column::TYPE_DATE);
        $table->addColumn('created_at', Column::TYPE_DATETIME);
        $table->addColumn('tags', Column::TYPE_SET, [ColumnSettings::SETTING_VALUES => ['text']]);
        $table->addColumn('scores', Column::TYPE_LIST, [ColumnSettings::SETTING_VALUES => ['int']]);
        $table->addColumn('meta', Column::TYPE_MAP, [ColumnSettings::SETTING_VALUES => ['text', 'int']]);
        $table->create();
        $this->runQueries($this->adapter->getQueryBuilder()->createTable($table));
    }

    /**
     * @param array<mixed> $queries
     */
    private function runQueries(array $queries): void
    {
        foreach ($queries as $query) {
            $this->adapter->query((string)$query);
        }
    }
}
