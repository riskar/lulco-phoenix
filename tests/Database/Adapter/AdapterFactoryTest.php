<?php

declare(strict_types=1);

namespace Phoenix\Tests\Database\Adapter;

use Phoenix\Config\EnvironmentConfig;
use Phoenix\Database\Adapter\AdapterFactory;
use Phoenix\Database\Adapter\CassandraAdapter;
use Phoenix\Database\Adapter\MysqlAdapter;
use Phoenix\Database\Adapter\PgsqlAdapter;
use Phoenix\Database\QueryBuilder\CassandraQueryBuilder;
use Phoenix\Database\QueryBuilder\MysqlQueryBuilder;
use Phoenix\Exception\InvalidArgumentValueException;
use Phoenix\Tests\Helpers\Adapter\CassandraCleanupAdapter;
use PHPUnit\Framework\TestCase;

final class AdapterFactoryTest extends TestCase
{
    public function testMysql(): void
    {
        $config = new EnvironmentConfig([
            'adapter' => 'mysql',
            'dsn' => 'sqlite::memory:',
        ]);
        $adapter = AdapterFactory::instance($config);
        $this->assertInstanceOf(MysqlAdapter::class, $adapter);
        $this->assertInstanceOf(MysqlQueryBuilder::class, $adapter->getQueryBuilder());
    }

    public function testPgsql(): void
    {
        $config = new EnvironmentConfig([
            'adapter' => 'pgsql',
            'dsn' => 'sqlite::memory:',
        ]);
        $adapter = AdapterFactory::instance($config);
        $this->assertInstanceOf(PgsqlAdapter::class, $adapter);
    }

    public function testCassandra(): void
    {
        if (!extension_loaded('cassandra')) {
            $this->markTestSkipped('Extension "cassandra" is not loaded.');
        }
        $config = new EnvironmentConfig([
            'adapter' => 'cassandra',
            'host' => getenv('PHOENIX_CASSANDRA_HOST') ?: 'localhost',
            'port' => (int)(getenv('PHOENIX_CASSANDRA_PORT') ?: 9042),
            'db_name' => getenv('PHOENIX_CASSANDRA_KEYSPACE') ?: 'phoenix',
        ]);
        (new CassandraCleanupAdapter((string)$config->getDbName()))->cleanupDatabase();

        $adapter = AdapterFactory::instance($config);
        $this->assertInstanceOf(CassandraAdapter::class, $adapter);
        $this->assertInstanceOf(CassandraQueryBuilder::class, $adapter->getQueryBuilder());
        $this->assertSame($config->getDbName(), $adapter->getKeyspace());
        $this->assertNotNull($adapter->getVersion());
    }

    public function testCassandraWithoutKeyspace(): void
    {
        if (!extension_loaded('cassandra')) {
            $this->markTestSkipped('Extension "cassandra" is not loaded.');
        }
        $this->expectException(InvalidArgumentValueException::class);
        $this->expectExceptionMessage('Adapter "cassandra" requires "db_name" (the keyspace) to be set.');
        AdapterFactory::instance(new EnvironmentConfig(['adapter' => 'cassandra']));
    }

    public function testUnknown(): void
    {
        $config = new EnvironmentConfig([
            'adapter' => 'unknown',
            'dsn' => 'sqlite::memory:',
        ]);

        $this->expectException(InvalidArgumentValueException::class);
        $this->expectExceptionMessage('Unknown adapter "unknown". Use one of value: "mysql", "pgsql", "cassandra".');
        AdapterFactory::instance($config);
    }
}
