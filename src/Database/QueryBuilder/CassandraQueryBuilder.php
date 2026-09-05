<?php

declare(strict_types=1);

namespace Phoenix\Database\QueryBuilder;

use Phoenix\Database\Element\Column;
use Phoenix\Database\Element\ForeignKey;
use Phoenix\Database\Element\Index;
use Phoenix\Database\Element\IndexColumn;
use Phoenix\Database\Element\IndexColumnSettings;
use Phoenix\Database\Element\MigrationTable;
use Phoenix\Database\Element\MigrationView;
use Phoenix\Database\Element\Table;
use Phoenix\Database\Element\UniqueConstraint;
use Phoenix\Exception\InvalidArgumentValueException;

/**
 * CQL query builder for Cassandra / ScyllaDB.
 *
 * Differences to the SQL builders, caused by CQL itself:
 * - the first primary column is the partition key, the remaining ones are clustering columns.
 *   A composite partition key is written as a single primary column wrapped in brackets,
 *   e.g. `$table->addPrimary(['(tenant_id, day)', 'created_at'])`
 * - a clustering column may carry its order, e.g. `$table->addPrimary(['id', 'created_at DESC'])`
 * - foreign keys and unique constraints do not exist in CQL and are rejected
 * - a secondary index is a standalone `CREATE INDEX` statement over exactly one column
 * - views are materialized views
 */
final class CassandraQueryBuilder extends CommonQueryBuilder implements QueryBuilderInterface
{
    public const PRIMARY_KEY_NAME = 'PRIMARY';

    protected function typeMap(): array
    {
        return [
            Column::TYPE_STRING => 'text',
            Column::TYPE_CHAR => 'text',
            Column::TYPE_TINY_TEXT => 'text',
            Column::TYPE_MEDIUM_TEXT => 'text',
            Column::TYPE_TEXT => 'text',
            Column::TYPE_LONG_TEXT => 'text',
            Column::TYPE_JSON => 'text',
            Column::TYPE_ENUM => 'text',
            Column::TYPE_ASCII => 'ascii',
            Column::TYPE_BIT => 'varint',
            Column::TYPE_TINY_INTEGER => 'tinyint',
            Column::TYPE_SMALL_INTEGER => 'smallint',
            Column::TYPE_MEDIUM_INTEGER => 'int',
            Column::TYPE_INTEGER => 'int',
            Column::TYPE_BIG_INTEGER => 'bigint',
            Column::TYPE_YEAR => 'smallint',
            Column::TYPE_VARINT => 'varint',
            Column::TYPE_COUNTER => 'counter',
            Column::TYPE_NUMERIC => 'decimal',
            Column::TYPE_DECIMAL => 'decimal',
            Column::TYPE_FLOAT => 'float',
            Column::TYPE_DOUBLE => 'double',
            Column::TYPE_BOOLEAN => 'boolean',
            Column::TYPE_BINARY => 'blob',
            Column::TYPE_VARBINARY => 'blob',
            Column::TYPE_TINY_BLOB => 'blob',
            Column::TYPE_MEDIUM_BLOB => 'blob',
            Column::TYPE_BLOB => 'blob',
            Column::TYPE_LONG_BLOB => 'blob',
            Column::TYPE_DATE => 'date',
            Column::TYPE_TIME => 'time',
            Column::TYPE_DATETIME => 'timestamp',
            Column::TYPE_TIMESTAMP => 'timestamp',
            Column::TYPE_TIMESTAMP_TZ => 'timestamp',
            Column::TYPE_DURATION => 'duration',
            Column::TYPE_UUID => 'uuid',
            Column::TYPE_TIMEUUID => 'timeuuid',
            Column::TYPE_INET => 'inet',
            Column::TYPE_POINT => 'text',
            Column::TYPE_LINE => 'text',
            Column::TYPE_POLYGON => 'text',
        ];
    }

    public function createTable(MigrationTable $table): array
    {
        $this->checkUnsupported($table);

        $columns = [];
        foreach ($table->getColumns() as $column) {
            $columns[] = $this->createColumn($column, $table);
        }
        $primaryKey = $this->createPrimaryKey($table);
        if ($primaryKey === '') {
            throw new InvalidArgumentValueException('Table "' . $table->getName() . '" has no primary key. Every CQL table needs one.');
        }
        $columns[] = $primaryKey;

        $query = 'CREATE TABLE ' . $this->escapeString($table->getName()) . ' (' . implode(',', $columns) . ')';
        $query .= $this->createTableOptions($table, true);
        $query .= ';';

        $queries = [$query];
        foreach ($table->getIndexes() as $index) {
            $queries[] = $this->createIndex($index, $table);
        }
        return $queries;
    }

    public function dropTable(MigrationTable $table): array
    {
        return ['DROP TABLE ' . $this->escapeString($table->getName()) . ';'];
    }

    public function truncateTable(MigrationTable $table): array
    {
        return ['TRUNCATE TABLE ' . $this->escapeString($table->getName()) . ';'];
    }

    public function renameTable(MigrationTable $table): array
    {
        throw new InvalidArgumentValueException('Cassandra does not support renaming of tables. Create a new table and copy the data instead.');
    }

    public function alterTable(MigrationTable $table): array
    {
        $this->checkUnsupported($table);
        if ($table->hasPrimaryKeyToDrop() || $table->getPrimaryColumns() || $table->getPrimaryColumnNames()) {
            throw new InvalidArgumentValueException('Cassandra does not support changing the primary key of an existing table "' . $table->getName() . '".');
        }

        $queries = [];
        foreach ($table->getIndexesToDrop() as $index) {
            $queries[] = 'DROP INDEX IF EXISTS ' . $this->escapeString($index) . ';';
        }

        // only primary key columns can be renamed in CQL, the server rejects the rest
        foreach ($table->getColumnsToRename() as $oldName => $newName) {
            $queries[] = 'ALTER TABLE ' . $this->escapeString($table->getName())
                . ' RENAME ' . $this->escapeString($oldName) . ' TO ' . $this->escapeString($newName) . ';';
        }

        foreach ($table->getColumnsToChange() as $oldName => $column) {
            if ($oldName !== $column->getName()) {
                throw new InvalidArgumentValueException('Cassandra cannot change name and type of column "' . $oldName . '" at once. Use renameColumn() and changeColumn() separately.');
            }
            $queries[] = 'ALTER TABLE ' . $this->escapeString($table->getName())
                . ' ALTER ' . $this->escapeString($oldName) . ' TYPE ' . $this->createType($column, $table) . ';';
        }

        if (!empty($table->getColumnsToDrop())) {
            $queries[] = 'ALTER TABLE ' . $this->escapeString($table->getName())
                . ' DROP (' . implode(',', $this->escapeArray($table->getColumnsToDrop())) . ');';
        }

        $columnsToAdd = [];
        foreach ($table->getColumns() as $column) {
            $columnsToAdd[] = $this->createColumn($column, $table);
        }
        if (!empty($columnsToAdd)) {
            $queries[] = 'ALTER TABLE ' . $this->escapeString($table->getName()) . ' ADD (' . implode(',', $columnsToAdd) . ');';
        }

        foreach ($table->getIndexes() as $index) {
            $queries[] = $this->createIndex($index, $table);
        }

        $options = $this->createTableOptions($table);
        if ($options !== '') {
            $queries[] = 'ALTER TABLE ' . $this->escapeString($table->getName()) . $options . ';';
        }
        return $queries;
    }

    public function copyTable(MigrationTable $table): array
    {
        /** @var string $newTableName */
        $newTableName = $table->getNewName();
        $oldTable = $this->adapter->getStructure()->getTable($table->getName());
        if (!$oldTable instanceof Table) {
            throw new InvalidArgumentValueException('Table "' . $table->getName() . '" doesn\'t exist.');
        }

        $queries = [];
        if ($table->getCopyType() !== MigrationTable::COPY_ONLY_DATA) {
            // CQL has no CREATE TABLE ... LIKE, the structure has to be rebuilt from the loaded one
            $newTable = $oldTable->toMigrationTable();
            $newTable->setName($newTableName);
            $newTable->create();
            $queries = array_merge($queries, $this->createTable($newTable));
        }

        if ($table->getCopyType() !== MigrationTable::COPY_ONLY_STRUCTURE) {
            if ($table->getDataChunkSize() !== null) {
                throw new InvalidArgumentValueException('CQL has no OFFSET, so data cannot be copied in chunks, table "' . $table->getName() . '".');
            }
            if ($table->getPrimaryColumnsValuesFunction() !== null) {
                return array_merge($queries, $this->copyAndAddData($table));
            }
            // CQL has no INSERT ... SELECT, rows are read and inserted back
            $data = $this->adapter->fetchAll($table->getName());
            if ($data !== []) {
                $queries[] = $this->adapter->buildInsertQuery($newTableName, $data);
            }
        }
        return $queries;
    }

    public function createView(MigrationView $view): array
    {
        return ['CREATE MATERIALIZED VIEW ' . $this->escapeString($view->getName()) . ' AS ' . $view->getSql() . ';'];
    }

    public function replaceView(MigrationView $view): array
    {
        // CQL knows no CREATE OR REPLACE for materialized views
        return array_merge($this->dropView($view), $this->createView($view));
    }

    public function dropView(MigrationView $view): array
    {
        return ['DROP MATERIALIZED VIEW IF EXISTS ' . $this->escapeString($view->getName()) . ';'];
    }

    public function escapeString(?string $string): string
    {
        return '"' . str_replace('"', '""', (string)$string) . '"';
    }

    protected function createColumn(Column $column, MigrationTable $table): string
    {
        $col = $this->escapeString($column->getName()) . ' ' . $this->createType($column, $table);
        if ($column->getSettings()->isAutoincrement()) {
            throw new InvalidArgumentValueException('Cassandra has no autoincrement. Use a uuid or timeuuid column for "' . $column->getName() . '" instead.');
        }
        if ($column->getSettings()->getDefault() !== null) {
            throw new InvalidArgumentValueException('Cassandra has no column default values, column "' . $column->getName() . '".');
        }
        return $col;
    }

    /**
     * A `set` column is stored as a CQL set. Its element type may be given through the `values` setting
     * (`['int']`); a list of enum-like values, as MySQL uses it, falls back to `set<text>`.
     */
    protected function createEnumSetColumn(Column $column, MigrationTable $table): string
    {
        if ($column->getType() === Column::TYPE_ENUM) {
            return 'text';
        }
        $values = array_values(array_map('strval', $column->getSettings()->getValues() ?: []));
        if (count($values) === 1 && in_array($values[0], $this->typeMap(), true)) {
            return 'set<' . $values[0] . '>';
        }
        return 'set<text>';
    }

    protected function createType(Column $column, MigrationTable $table): string
    {
        $type = $column->getType();
        if (in_array($type, [Column::TYPE_LIST, Column::TYPE_MAP, Column::TYPE_TUPLE], true)) {
            return $this->createCollectionType($column);
        }
        return parent::createType($column, $table);
    }

    protected function primaryKeyString(MigrationTable $table): string
    {
        $primaryColumns = $table->getPrimaryColumnNames();

        $partitionKey = (string)array_shift($primaryColumns);
        if (strpos($partitionKey, '(') === 0) {
            $names = array_filter(array_map('trim', explode(',', trim($partitionKey, '()'))));
            $partitionKey = '(' . implode(',', $this->escapeArray($names)) . ')';
        } else {
            $partitionKey = $this->escapeString($partitionKey);
        }

        $clusteringColumns = [];
        foreach ($primaryColumns as $primaryColumn) {
            [$name, ] = $this->splitClusteringColumn($primaryColumn);
            $clusteringColumns[] = $this->escapeString($name);
        }

        return 'PRIMARY KEY (' . implode(',', array_merge([$partitionKey], $clusteringColumns)) . ')';
    }

    /**
     * splits "created_at DESC" into the column name and its clustering order
     *
     * @return array{string, ?string}
     */
    private function splitClusteringColumn(string $primaryColumn): array
    {
        if (preg_match('/^(.*?)\s+(ASC|DESC)$/i', trim($primaryColumn), $matches) === 1) {
            return [$matches[1], strtoupper($matches[2])];
        }
        return [$primaryColumn, null];
    }

    private function createCollectionType(Column $column): string
    {
        $values = array_values(array_map('strval', $column->getSettings()->getValues() ?: []));
        if ($column->getType() === Column::TYPE_LIST) {
            return 'list<' . ($values[0] ?? 'text') . '>';
        }
        if ($column->getType() === Column::TYPE_MAP) {
            return 'map<' . ($values[0] ?? 'text') . ',' . ($values[1] ?? 'text') . '>';
        }
        return 'tuple<' . implode(',', $values ?: ['text']) . '>';
    }

    private function createTableOptions(MigrationTable $table, bool $withClusteringOrder = false): string
    {
        $options = [];
        if ($withClusteringOrder) {
            $orders = [];
            $hasExplicitOrder = false;
            foreach (array_slice($table->getPrimaryColumnNames(), 1) as $clusteringColumn) {
                [$name, $order] = $this->splitClusteringColumn($clusteringColumn);
                $hasExplicitOrder = $hasExplicitOrder || $order !== null;
                $orders[] = $this->escapeString($name) . ' ' . ($order ?? IndexColumnSettings::SETTING_ORDER_ASC);
            }
            if ($hasExplicitOrder) {
                $options[] = 'CLUSTERING ORDER BY (' . implode(',', $orders) . ')';
            }
        }
        if ($table->getComment() !== null) {
            $options[] = "comment = '" . str_replace("'", "''", $table->getComment()) . "'";
        }
        return $options === [] ? '' : ' WITH ' . implode(' AND ', $options);
    }

    private function createIndex(Index $index, MigrationTable $table): string
    {
        if ($index->getName() === self::PRIMARY_KEY_NAME) {
            return '';
        }
        $columns = $index->getColumns();
        if (count($columns) !== 1) {
            throw new InvalidArgumentValueException('Cassandra secondary index "' . $index->getName() . '" has to be created on exactly one column, ' . count($columns) . ' given.');
        }
        if ($index->getType() === Index::TYPE_UNIQUE) {
            throw new InvalidArgumentValueException('Cassandra does not support unique indexes, index "' . $index->getName() . '".');
        }
        if ($index->getType() === Index::TYPE_FULLTEXT) {
            throw new InvalidArgumentValueException('Cassandra does not support fulltext indexes, index "' . $index->getName() . '".');
        }
        /** @var IndexColumn $indexColumn */
        $indexColumn = current($columns);

        return 'CREATE INDEX IF NOT EXISTS ' . $this->escapeString($index->getName())
            . ' ON ' . $this->escapeString($table->getName())
            . ' (' . $this->escapeString($indexColumn->getName()) . ');';
    }

    private function checkUnsupported(MigrationTable $table): void
    {
        if ($table->getForeignKeys() || $table->getForeignKeysToDrop()) {
            throw new InvalidArgumentValueException('Cassandra does not support foreign keys, table "' . $table->getName() . '".');
        }
        if ($table->getUniqueConstraints() || $table->getUniqueConstraintsToDrop()) {
            throw new InvalidArgumentValueException('Cassandra does not support unique constraints, table "' . $table->getName() . '".');
        }
        if ($table->getAutoIncrement() !== null) {
            throw new InvalidArgumentValueException('Cassandra does not support autoincrement, table "' . $table->getName() . '".');
        }
    }

    protected function createForeignKey(ForeignKey $foreignKey, MigrationTable $table): string
    {
        throw new InvalidArgumentValueException('Cassandra does not support foreign keys, table "' . $table->getName() . '".');
    }

    protected function createUniqueConstraint(UniqueConstraint $uniqueConstraint): string
    {
        throw new InvalidArgumentValueException('Cassandra does not support unique constraints, constraint "' . $uniqueConstraint->getName() . '".');
    }
}
