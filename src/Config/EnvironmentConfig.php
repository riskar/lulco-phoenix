<?php

declare(strict_types=1);

namespace Phoenix\Config;

final class EnvironmentConfig
{
    /**
     * @var array<string, mixed>
     */
    private array $configuration;

    /**
     * @param array<string, mixed> $configuration
     */
    public function __construct(array $configuration)
    {
        $this->configuration = $configuration;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    public function getAdapter(): string
    {
        return $this->configuration['adapter'];
    }

    public function getDsn(): string
    {
        if ($this->checkConfigValue('dsn')) {
            return $this->configuration['dsn'];
        }
        $adapter = $this->getAdapter();
        $dsn = $adapter . ':dbname=' . $this->configuration['db_name'] . ';host=' . $this->configuration['host'];
        if ($this->checkConfigValue('port')) {
            $dsn .= ';port=' . $this->configuration['port'];
        }
        if ($this->checkConfigValue('charset')) {
            if ($adapter === 'pgsql') {
                $dsn .= ';options=\'--client_encoding=' . $this->configuration['charset'] . '\'';
            } else {
                $dsn .= ';charset=' . $this->configuration['charset'];
            }
        }
        return $dsn;
    }

    public function getUsername(): ?string
    {
        return $this->configuration['username'] ?? null;
    }

    public function getPassword(): ?string
    {
        return $this->configuration['password'] ?? null;
    }

    public function getCharset(): string
    {
        return $this->configuration['charset'] ?? ($this->getAdapter() === 'mysql' ? 'utf8mb4' : 'utf8');
    }

    public function getCollation(): ?string
    {
        return $this->configuration['collation'] ?? null;
    }

    public function getVersion(): ?string
    {
        return $this->configuration['version'] ?? null;
    }

    /**
     * @return string[] one or more contact points of the cluster
     */
    public function getHosts(): array
    {
        $host = $this->configuration['host'] ?? 'localhost';
        return array_values(array_filter(array_map('trim', explode(',', (string)$host))));
    }

    public function getPort(?int $default = null): ?int
    {
        return isset($this->configuration['port']) ? (int)$this->configuration['port'] : $default;
    }

    public function getDbName(): ?string
    {
        return $this->configuration['db_name'] ?? null;
    }

    /**
     * @return mixed
     */
    public function getOption(string $name)
    {
        return $this->configuration[$name] ?? null;
    }

    private function checkConfigValue(string $key): bool
    {
        return isset($this->configuration[$key]) && $this->configuration[$key];
    }
}
