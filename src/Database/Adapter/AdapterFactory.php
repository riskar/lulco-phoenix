<?php

declare(strict_types=1);

namespace Phoenix\Database\Adapter;

use Cassandra;
use Cassandra\Cluster\Builder;
use Cassandra\SimpleStatement;
use PDO;
use Phoenix\Config\EnvironmentConfig;
use Phoenix\Exception\InvalidArgumentValueException;
use Throwable;

final class AdapterFactory
{
    public const string ADAPTER_MYSQL = 'mysql';

    public const string ADAPTER_PGSQL = 'pgsql';

    public const string ADAPTER_CASSANDRA = 'cassandra';

    public static function instance(EnvironmentConfig $config): AdapterInterface
    {
        $adapter = $config->getAdapter();
        if ($adapter === self::ADAPTER_CASSANDRA) {
            return self::createCassandraAdapter($config);
        }
        if ($adapter !== self::ADAPTER_MYSQL && $adapter !== self::ADAPTER_PGSQL) {
            throw new InvalidArgumentValueException('Unknown adapter "' . $adapter . '". Use one of value: "mysql", "pgsql", "cassandra".');
        }

        $pdo = new PDO($config->getDsn(), $config->getUsername(), $config->getPassword(), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT
        ]);

        $version = self::normalizeVersion($config->getVersion() ?? $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));

        $pdoAdapter = $adapter === self::ADAPTER_MYSQL ? new MysqlAdapter($pdo, $version) : new PgsqlAdapter($pdo, $version);
        $pdoAdapter->setCharset($config->getCharset());
        $pdoAdapter->setCollation($config->getCollation());
        return $pdoAdapter;
    }

    private static function createCassandraAdapter(EnvironmentConfig $config): CassandraAdapter
    {
        if (!extension_loaded('cassandra')) {
            throw new InvalidArgumentValueException('Adapter "cassandra" requires the "cassandra" PHP extension (DataStax PHP driver) to be installed.');
        }
        $keyspace = $config->getDbName();
        if (!$keyspace) {
            throw new InvalidArgumentValueException('Adapter "cassandra" requires "db_name" (the keyspace) to be set.');
        }

        /** @var Builder $builder */
        $builder = Cassandra::cluster();
        $builder->withContactPoints(...$config->getHosts());
        $builder->withPort($config->getPort(9042) ?? 9042);

        $username = $config->getUsername();
        $password = $config->getPassword();
        if ($username !== null && $password !== null) {
            $builder->withCredentials($username, $password);
        }

        self::applyOptionalSettings($builder, $config);

        $session = $builder->build()->connect($keyspace);

        $version = $config->getVersion();
        if ($version === null) {
            $version = self::loadCassandraVersion($session);
        }
        $adapter = new CassandraAdapter($session, $keyspace, self::normalizeVersion($version));
        $adapter->setCharset($config->getCharset());
        $adapter->setCollation($config->getCollation());
        return $adapter;
    }

    private static function applyOptionalSettings(Builder $builder, EnvironmentConfig $config): void
    {
        $consistency = $config->getOption('consistency');
        if (is_string($consistency)) {
            $constant = 'Cassandra::CONSISTENCY_' . strtoupper($consistency);
            if (!defined($constant)) {
                throw new InvalidArgumentValueException('Unknown consistency level "' . $consistency . '".');
            }
            /** @var int $consistencyValue */
            $consistencyValue = constant($constant);
            $builder->withDefaultConsistency($consistencyValue);
        }

        $pageSize = $config->getOption('page_size');
        if (is_numeric($pageSize)) {
            $builder->withDefaultPageSize((int)$pageSize);
        }

        $connectTimeout = $config->getOption('connect_timeout');
        if (is_numeric($connectTimeout)) {
            $builder->withConnectTimeout((float)$connectTimeout);
        }

        $requestTimeout = $config->getOption('request_timeout');
        if (is_numeric($requestTimeout)) {
            $builder->withRequestTimeout((float)$requestTimeout);
        }

        $datacenter = $config->getOption('datacenter');
        if (is_string($datacenter) && $datacenter !== '') {
            $builder->withDatacenterAwareRoundRobinLoadBalancingPolicy($datacenter, 0, false);
        }

        if ($config->getOption('ssl')) {
            $sslBuilder = Cassandra::ssl();
            $trustedCerts = $config->getOption('ssl_trusted_certs');
            if (is_string($trustedCerts) && $trustedCerts !== '') {
                $sslBuilder->withTrustedCerts($trustedCerts);
            }
            $builder->withSSL($sslBuilder->build());
        }
    }

    /**
     * @param mixed $session
     */
    private static function loadCassandraVersion($session): ?string
    {
        try {
            $rows = $session->execute(new SimpleStatement('SELECT release_version FROM system.local'));
            foreach ($rows as $row) {
                return (string)$row['release_version'];
            }
        } catch (Throwable $e) {
            return null;
        }
        return null;
    }

    private static function normalizeVersion(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }
        sscanf($version, '%d.%d.%d', $v1, $v2, $v3);
        return implode('.', array_filter([$v1, $v2, $v3], function ($v) {
            return $v !== null;
        }));
    }
}
