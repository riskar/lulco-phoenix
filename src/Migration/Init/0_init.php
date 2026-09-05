<?php

declare(strict_types=1);

namespace Phoenix\Migration\Init;

use Phoenix\Database\Adapter\AdapterInterface;
use Phoenix\Database\Adapter\CassandraAdapter;
use Phoenix\Database\Element\Column;
use Phoenix\Exception\InvalidArgumentValueException;
use Phoenix\Migration\AbstractMigration;

final class Init extends AbstractMigration
{
    private string $logTableName;

    private AdapterInterface $initAdapter;

    public function __construct(AdapterInterface $adapter, string $logTableName)
    {
        parent::__construct($adapter);
        $this->initAdapter = $adapter;
        $this->logTableName = $logTableName;
    }

    /**
     * @throws InvalidArgumentValueException
     */
    protected function up(): void
    {
        $this->table($this->logTableName, $this->primaryKey())
            ->addColumn('migration_datetime', Column::TYPE_STRING)
            ->addColumn('classname', Column::TYPE_STRING)
            ->addColumn('executed_at', Column::TYPE_DATETIME)
            ->create();
    }

    /**
     * CQL has no autoincrement and every row is addressed by its primary key, so the log table is
     * keyed by the columns which identify a migration instead of by a generated id.
     *
     * @return mixed
     */
    private function primaryKey()
    {
        if ($this->initAdapter instanceof CassandraAdapter) {
            return ['migration_datetime', 'classname'];
        }
        return true;
    }

    protected function down(): void
    {
        $this->table($this->logTableName)
            ->drop();
    }
}
