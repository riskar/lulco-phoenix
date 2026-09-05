<?php

declare(strict_types=1);

namespace Phoenix\Tests\Helpers\Cassandra;

use Cassandra;
use Cassandra\Session;

final class CassandraSessionFactory
{
    public static function host(): string
    {
        return (string)(getenv('PHOENIX_CASSANDRA_HOST') ?: 'localhost');
    }

    public static function port(): int
    {
        return (int)(getenv('PHOENIX_CASSANDRA_PORT') ?: 9042);
    }

    public static function keyspace(): string
    {
        return (string)(getenv('PHOENIX_CASSANDRA_KEYSPACE') ?: 'phoenix');
    }

    public static function create(?string $keyspace = null): Session
    {
        $builder = Cassandra::cluster()
            ->withContactPoints(self::host())
            ->withPort(self::port());

        $username = getenv('PHOENIX_CASSANDRA_USERNAME');
        $password = getenv('PHOENIX_CASSANDRA_PASSWORD');
        if ($username !== false && $username !== '' && $password !== false) {
            $builder->withCredentials($username, (string)$password);
        }

        $cluster = $builder->build();
        return $keyspace === null ? $cluster->connect() : $cluster->connect($keyspace);
    }
}
