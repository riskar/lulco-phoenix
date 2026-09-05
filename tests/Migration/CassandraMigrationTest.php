<?php

declare(strict_types=1);

namespace Phoenix\Tests\Migration;

use Phoenix\Config\Config;
use Phoenix\Database\Adapter\CassandraAdapter;
use Phoenix\Database\Element\Column;
use Phoenix\Database\Element\MigrationTable;
use Phoenix\Migration\AbstractMigration;
use Phoenix\Migration\Init\Init;
use Phoenix\Migration\Manager;
use Phoenix\Tests\Helpers\Adapter\CassandraCleanupAdapter;
use Phoenix\Tests\Helpers\Cassandra\CassandraSessionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Covers the whole migration lifecycle on Cassandra: the log table created by Init, logging and
 * removing an execution through Manager and running a migration through AbstractMigration.
 */
final class CassandraMigrationTest extends TestCase
{
    private CassandraAdapter $adapter;

    protected function setUp(): void
    {
        if (!extension_loaded('cassandra')) {
            $this->markTestSkipped('Extension "cassandra" is not loaded.');
        }
        $keyspace = CassandraSessionFactory::keyspace();
        (new CassandraCleanupAdapter($keyspace))->cleanupDatabase();
        $this->adapter = new CassandraAdapter(CassandraSessionFactory::create($keyspace), $keyspace);

        require_once __DIR__ . '/../../src/Migration/Init/0_init.php';
    }

    public function testInitCreatesLogTableKeyedByMigrationIdentity(): void
    {
        $init = new Init($this->adapter, 'phoenix_log');
        $init->migrate();

        $this->assertSame(
            ['CREATE TABLE "phoenix_log" ("migration_datetime" text,"classname" text,"executed_at" timestamp,'
                . 'PRIMARY KEY ("migration_datetime","classname"));'],
            $init->getExecutedQueries()
        );

        $logTable = $this->adapter->getStructure()->getTable('phoenix_log');
        $this->assertNotNull($logTable);
        // CQL has no autoincrement, so the log table is keyed by the migration identity instead of an id
        $this->assertSame(['migration_datetime', 'classname'], $logTable->getPrimary());
        $this->assertNull($logTable->getColumn('id'));
    }

    public function testInitRollbackDropsLogTable(): void
    {
        $init = new Init($this->adapter, 'phoenix_log');
        $init->migrate();
        $init->rollback();

        $this->assertSame(['DROP TABLE "phoenix_log";'], $init->getExecutedQueries());
        $this->assertNull($this->adapter->getStructure()->getTable('phoenix_log'));
    }

    public function testLogAndRemoveExecution(): void
    {
        (new Init($this->adapter, 'phoenix_log'))->migrate();

        $config = new Config([
            'migration_dirs' => ['first' => __DIR__ . '/../fake/structure/migration_directory_1'],
            'environments' => ['cassandra' => ['adapter' => 'cassandra']],
        ]);
        $manager = new Manager($config, $this->adapter);
        $this->assertSame([], $manager->executedMigrations());

        $migrations = $manager->findMigrationClasses();
        $this->assertNotEmpty($migrations);
        /** @var AbstractMigration $migration */
        $migration = current($migrations);

        $manager->logExecution($migration);
        $executedMigrations = $manager->executedMigrations();
        $this->assertCount(1, $executedMigrations);

        $identifier = $migration->getDatetime() . '|' . $migration->getFullClassName();
        $this->assertArrayHasKey($identifier, $executedMigrations);
        $this->assertSame($migration->getDatetime(), $executedMigrations[$identifier]['migration_datetime']);
        $this->assertSame($migration->getFullClassName(), $executedMigrations[$identifier]['classname']);
        $this->assertNotEmpty($executedMigrations[$identifier]['executed_at']);

        $manager->removeExecution($migration);
        $this->assertSame([], $manager->executedMigrations());
    }

    public function testMigrationCreatesTableAndInsertsData(): void
    {
        $migration = new class ($this->adapter) extends AbstractMigration {
            protected function up(): void
            {
                $table = $this->table('articles', 'id');
                $table->addColumn('id', Column::TYPE_UUID);
                $table->addColumn('title', Column::TYPE_STRING);
                $table->create();

                $this->insert('articles', ['id' => '550e8400-e29b-41d4-a716-446655440000', 'title' => 'first']);
            }

            protected function down(): void
            {
                $table = $this->table('articles');
                $table->drop();
            }
        };

        $migration->migrate();
        $executedQueries = $migration->getExecutedQueries();
        $this->assertCount(2, $executedQueries);
        $this->assertStringStartsWith('CREATE TABLE "articles"', $executedQueries[0]);
        $this->assertStringStartsWith('INSERT INTO "articles"', $executedQueries[1]);

        $rows = $this->adapter->fetchAll('articles');
        $this->assertCount(1, $rows);
        $this->assertSame('first', $rows[0]['title']);

        $migration->rollback();
        $this->assertSame(['DROP TABLE "articles";'], $migration->getExecutedQueries());
        $this->assertNull($this->adapter->getStructure()->getTable('articles'));
    }

    public function testForeignKeyChecksProduceNoQueries(): void
    {
        $migration = new class ($this->adapter) extends AbstractMigration {
            protected function up(): void
            {
                $this->checkForeignKeysOff();
                $this->checkForeignKeysOn();
            }

            protected function down(): void
            {
            }
        };

        $migration->migrate();
        // there are no foreign keys in CQL, so the empty queries are skipped instead of being executed
        $this->assertSame([], $migration->getExecutedQueries());
    }

    public function testDryRunExecutesNothing(): void
    {
        $migration = new class ($this->adapter) extends AbstractMigration {
            protected function up(): void
            {
                $table = $this->table('dry_run', 'id');
                $table->addColumn('id', Column::TYPE_UUID);
                $table->create();
            }

            protected function down(): void
            {
            }
        };

        $this->assertSame([], $migration->migrate(true));
        $this->assertCount(1, $migration->getExecutedQueries());
        $this->assertNull($this->adapter->getStructure()->getTable('dry_run'));
    }

    public function testTableStructureIsUpdatedByMigration(): void
    {
        $migrationTable = new MigrationTable('structure_check', 'id');
        $migrationTable->addColumn('id', Column::TYPE_UUID);
        $migrationTable->create();
        foreach ($this->adapter->getQueryBuilder()->createTable($migrationTable) as $query) {
            $this->adapter->query((string)$query);
        }

        $this->assertNotNull($this->adapter->getStructure()->getTable('structure_check'));
    }
}
