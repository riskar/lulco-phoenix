<?php

declare(strict_types=1);

namespace Phoenix\Database\Adapter;

/**
 * Adapter which cannot use the default autoincrement "id" primary key for the migration log table
 * (e.g. CQL has no autoincrement) tells the init migration what to use instead.
 */
interface LogTablePrimaryKeyInterface
{
    /**
     * @return mixed anything accepted by AbstractMigration::table() as primary key
     */
    public function getLogTablePrimaryKey(): mixed;
}
