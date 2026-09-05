<?php

declare(strict_types=1);

namespace Phoenix\Database\Adapter;

/**
 * CQL statement with its named arguments.
 *
 * Arguments are bound by name (`:name` placeholders in the CQL string), the same way
 * PdoAdapter binds them, so both adapters build queries in a comparable shape.
 */
final class CassandraStatement implements StatementInterface
{
    private string $cql;

    /** @var array<string, mixed> raw PHP values, keyed by placeholder name */
    private array $arguments;

    private ?string $table;

    /** @var array<string, string> placeholder name => column the value belongs to */
    private array $argumentColumns;

    /** @var array<array<string, mixed>> */
    private array $rows = [];

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, string> $argumentColumns
     */
    public function __construct(string $cql, array $arguments = [], ?string $table = null, array $argumentColumns = [])
    {
        $this->cql = $cql;
        $this->arguments = $arguments;
        $this->table = $table;
        $this->argumentColumns = $argumentColumns;
    }

    public function getQueryString(): string
    {
        return $this->cql;
    }

    /**
     * @return array<string, mixed>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function getTable(): ?string
    {
        return $this->table;
    }

    /**
     * Values are typed by the column they are written to, and that column may be created by an
     * earlier query of the very same migration - so the mapping is resolved when the statement runs.
     *
     * @return array<string, string>
     */
    public function getArgumentColumns(): array
    {
        return $this->argumentColumns;
    }

    /**
     * @param array<array<string, mixed>> $rows
     */
    public function setRows(array $rows): void
    {
        $this->rows = $rows;
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function fetchAll(): array
    {
        return $this->rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetch(): ?array
    {
        return $this->rows[0] ?? null;
    }

    /**
     * @return mixed
     */
    public function fetchColumn(int $index = 0)
    {
        $row = $this->fetch();
        if ($row === null) {
            return false;
        }
        return array_values($row)[$index] ?? false;
    }

    public function __toString(): string
    {
        return $this->cql;
    }
}
