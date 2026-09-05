<?php

declare(strict_types=1);

namespace Phoenix\Database\Adapter;

use Cassandra;
use Cassandra\Bigint;
use Cassandra\Blob;
use Cassandra\Collection;
use Cassandra\Date;
use Cassandra\Time;
use Cassandra\Duration;
use Cassandra\Inet;
use Cassandra\Map;
use Cassandra\Rows;
use Cassandra\Session;
use Cassandra\SimpleStatement;
use Cassandra\Smallint;
use Cassandra\Timestamp;
use Cassandra\Timeuuid;
use Cassandra\Tinyint;
use Cassandra\Tuple;
use Cassandra\Type;
use Cassandra\Uuid;
use Cassandra\UserTypeValue;
use Cassandra\Value;
use DateTimeInterface;
use InvalidArgumentException;
use Phoenix\Database\Adapter\Behavior\StructureBehavior;
use Phoenix\Database\Element\Column;
use Phoenix\Database\Element\ColumnSettings;
use Phoenix\Database\Element\Index;
use Phoenix\Database\Element\IndexColumn;
use Phoenix\Database\Element\MigrationTable;
use Phoenix\Database\QueryBuilder\CassandraQueryBuilder;
use Phoenix\Exception\DatabaseQueryExecuteException;
use PDOStatement;
use Throwable;
use UnexpectedValueException;

/**
 * Adapter for Cassandra and its drop-in replacements (ScyllaDB).
 *
 * Requires the `cassandra` PHP extension (DataStax PHP driver).
 */
final class CassandraAdapter implements AdapterInterface
{
    use StructureBehavior;

    /** columns of these kinds form the primary key, ordered as CQL orders them */
    private const string KIND_PARTITION_KEY = 'partition_key';

    private const string KIND_CLUSTERING = 'clustering';

    private Session $session;

    private string $keyspace;

    private ?string $version;

    private ?string $charset = null;

    private ?string $collation = null;

    private ?CassandraQueryBuilder $queryBuilder = null;

    /** @var array<string, array<string, array{type: string, kind: string}>>|null table name => column name => metadata */
    private ?array $columnMeta = null;

    public function __construct(Session $session, string $keyspace, ?string $version = null)
    {
        $this->session = $session;
        $this->keyspace = $keyspace;
        $this->version = $version;
    }

    public function getSession(): Session
    {
        return $this->session;
    }

    public function getKeyspace(): string
    {
        return $this->keyspace;
    }

    public function getQueryBuilder(): CassandraQueryBuilder
    {
        if (!$this->queryBuilder) {
            $this->queryBuilder = new CassandraQueryBuilder($this);
        }
        return $this->queryBuilder;
    }

    /**
     * @param PDOStatement|StatementInterface $sql
     * @throws DatabaseQueryExecuteException on error
     */
    public function execute(PDOStatement|StatementInterface $sql): bool
    {
        if (!$sql instanceof CassandraStatement) {
            throw new InvalidArgumentException('Only CassandraStatement can be executed by ' . static::class);
        }
        $rows = $this->run($sql->getQueryString(), $this->resolveArguments($sql));
        $sql->setRows($rows);
        return true;
    }

    /**
     * @throws DatabaseQueryExecuteException on error
     */
    public function query(string $sql): CassandraStatement
    {
        $statement = new CassandraStatement($sql);
        $statement->setRows($this->run($sql));
        return $statement;
    }

    /**
     * {@inheritdoc}
     */
    public function insert(string $table, array $data)
    {
        $statement = $this->buildInsertQuery($table, $data);
        $this->execute($statement);
        return null;    // CQL has no generated ids
    }

    public function buildInsertQuery(string $table, array $data): CassandraStatement
    {
        if ($this->isMulti($data)) {
            /** @var array<array<string, mixed>> $data */
            return $this->buildBatchInsertQuery($table, $data);
        }
        /** @var array<string, mixed> $data */
        $keys = array_keys($data);
        $query = sprintf(
            'INSERT INTO %s (%s) VALUES (%s);',
            $this->escapeString($table),
            implode(', ', array_map([$this, 'escapeString'], $keys)),
            implode(', ', array_map(fn(string $key): string => ':' . $key, $keys))
        );
        return new CassandraStatement($query, $data, $table, array_combine($keys, $keys));
    }

    public function update(string $table, array $data, array $conditions = [], string $where = ''): bool
    {
        return $this->execute($this->buildUpdateQuery($table, $data, $conditions, $where));
    }

    public function buildUpdateQuery(string $table, array $data, array $conditions = [], string $where = ''): CassandraStatement
    {
        $values = [];
        foreach (array_keys($data) as $key) {
            $values[] = $this->escapeString($key) . ' = :' . $key;
        }
        $query = sprintf(
            'UPDATE %s SET %s%s;',
            $this->escapeString($table),
            implode(', ', $values),
            $this->createWhere($conditions, $where)
        );
        $conditionValues = $this->createConditionValues($conditions);
        $dataColumns = array_combine(array_keys($data), array_keys($data));
        return new CassandraStatement(
            $query,
            array_merge($data, $conditionValues['arguments']),
            $table,
            array_merge($dataColumns, $conditionValues['columns'])
        );
    }

    public function delete(string $table, array $conditions = [], string $where = ''): bool
    {
        return $this->execute($this->buildDeleteQuery($table, $conditions, $where));
    }

    public function buildDeleteQuery(string $table, array $conditions = [], string $where = ''): CassandraStatement
    {
        $query = sprintf('DELETE FROM %s%s;', $this->escapeString($table), $this->createWhere($conditions, $where));
        $conditionValues = $this->createConditionValues($conditions);
        return new CassandraStatement($query, $conditionValues['arguments'], $table, $conditionValues['columns']);
    }

    /**
     * there is nothing like a foreign key in CQL, so there is nothing to switch off either
     */
    public function buildDoNotCheckForeignKeysQuery(): string
    {
        return '';
    }

    public function buildCheckForeignKeysQuery(): string
    {
        return '';
    }

    public function select(string $sql): array
    {
        if (strpos(strtoupper(ltrim($sql)), 'SELECT ') !== 0) {
            throw new InvalidArgumentException('Only select query can be executed in select method');
        }
        return $this->run($sql);
    }

    public function fetch(string $table, array $fields = ['*'], array $conditions = [], array $orders = [], array $groups = []): ?array
    {
        $rows = $this->fetchAll($table, $fields, $conditions, '1', $orders, $groups);
        return $rows[0] ?? null;
    }

    public function fetchAll(string $table, array $fields = ['*'], array $conditions = [], ?string $limit = null, array $orders = [], array $groups = []): array
    {
        $phpOrders = $this->ordersToSortInPhp($orders, $table);
        $query = sprintf(
            'SELECT %s FROM %s%s%s%s%s;',
            implode(', ', $fields),
            $this->escapeString($table),
            $this->createWhere($conditions),
            $this->createGroup($groups),
            $this->createOrder($orders, $table),
            $phpOrders === [] ? $this->createLimit($limit) : ''
        );
        $conditionValues = $this->createConditionValues($conditions);
        $rows = $this->run($query, $this->createRealValues($table, $conditionValues['arguments'], $conditionValues['columns']));
        if ($phpOrders === []) {
            return $rows;
        }
        // CQL can only order by clustering columns, everything else has to be sorted and limited here
        $rows = $this->sortRows($rows, $phpOrders);
        return $limit === null ? $rows : array_slice($rows, 0, (int)$limit);
    }

    /**
     * CQL has no transactions - a migration on Cassandra is never atomic
     */
    public function startTransaction(): bool
    {
        return true;
    }

    public function commit(): bool
    {
        return true;
    }

    public function rollback(): bool
    {
        return true;
    }

    public function setCharset(string $charset): AdapterInterface
    {
        $this->charset = $charset;
        return $this;
    }

    public function getCharset(): ?string
    {
        return $this->charset;
    }

    public function setCollation(?string $collation): AdapterInterface
    {
        $this->collation = $collation;
        return $this;
    }

    public function getCollation(): ?string
    {
        return $this->collation;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function escapeString(string $string): string
    {
        return '"' . str_replace('"', '""', $string) . '"';
    }

    // ------------------------------------------------------------------ structure

    protected function loadDatabase(): string
    {
        return $this->keyspace;
    }

    protected function loadTables(string $database): array
    {
        $tables = $this->run(sprintf(
            "SELECT table_name, comment FROM system_schema.tables WHERE keyspace_name = '%s'",
            $this->escapeLiteral($database)
        ));
        usort($tables, fn(array $a, array $b): int => strcmp((string)$a['table_name'], (string)$b['table_name']));
        return $tables;
    }

    /**
     * @param array<string, string> $table
     */
    protected function createMigrationTable(array $table): MigrationTable
    {
        $migrationTable = new MigrationTable($table['table_name'], false);
        if (isset($table['comment']) && $table['comment'] !== '') {
            $migrationTable->setComment($table['comment']);
        }
        return $migrationTable;
    }

    protected function loadColumns(string $database): array
    {
        $columns = $this->run(sprintf(
            "SELECT table_name, column_name, type, kind, position, clustering_order FROM system_schema.columns WHERE keyspace_name = '%s'",
            $this->escapeLiteral($database)
        ));

        $tableNames = [];
        foreach ($this->loadTables($database) as $table) {
            $tableNames[$table['table_name']] = true;
        }

        $tablesColumns = [];
        foreach ($columns as $column) {
            /** @var string $tableName */
            $tableName = $column['table_name'];
            if (!isset($tableNames[$tableName])) {
                continue;   // backing tables of secondary indexes and materialized views
            }
            $tablesColumns[$tableName][] = $column;
        }
        foreach ($tablesColumns as $tableName => $tableColumns) {
            usort($tableColumns, fn(array $a, array $b): int => strcmp((string)$a['column_name'], (string)$b['column_name']));
            $tablesColumns[$tableName] = $tableColumns;
        }
        return $tablesColumns;
    }

    protected function addColumn(MigrationTable $migrationTable, array $column): void
    {
        /** @var string $cqlType */
        $cqlType = $column['type'];
        [$type, $settings] = $this->remapType($cqlType);
        $settings[ColumnSettings::SETTING_NULL] = $column['kind'] === 'regular' || $column['kind'] === 'static';
        /** @var array{null?: bool, values?: array<int|string, int|string>} $settings */
        $migrationTable->addColumn($column['column_name'], $type, $settings);
    }

    /**
     * the primary key is reported as an index called PRIMARY, the rest are CQL secondary indexes
     */
    protected function loadIndexes(string $database): array
    {
        $indexes = [];
        foreach ($this->loadPrimaryKeyColumns($database) as $tableName => $primaryColumns) {
            $indexes[$tableName][CassandraQueryBuilder::PRIMARY_KEY_NAME] = [
                'columns' => $this->createPrimaryIndexColumns($primaryColumns),
                'type' => Index::TYPE_NORMAL,
                'method' => Index::METHOD_DEFAULT,
            ];
        }

        $secondaryIndexes = $this->run(sprintf(
            "SELECT table_name, index_name, options FROM system_schema.indexes WHERE keyspace_name = '%s'",
            $this->escapeLiteral($database)
        ));
        foreach ($secondaryIndexes as $index) {
            /** @var array<string, string> $options */
            $options = $index['options'] ?? [];
            $target = $this->parseIndexTarget((string)($options['target'] ?? ''));
            if ($target === null) {
                continue;
            }
            $indexes[$index['table_name']][$index['index_name']] = [
                'columns' => [new IndexColumn($target)],
                'type' => Index::TYPE_NORMAL,
                'method' => Index::METHOD_DEFAULT,
            ];
        }
        return $indexes;
    }

    /**
     * CQL has no foreign keys
     */
    protected function loadForeignKeys(string $database): array
    {
        return [];
    }

    /**
     * CQL has no unique constraints
     */
    protected function loadUniqueConstraints(string $database): array
    {
        return [];
    }

    // ------------------------------------------------------------------ internals

    /**
     * @param array<string, mixed> $arguments
     * @return array<array<string, mixed>>
     * @throws DatabaseQueryExecuteException
     */
    private function run(string $cql, array $arguments = []): array
    {
        $cql = rtrim(trim($cql), ';');
        if ($cql === '') {
            return [];
        }
        if ($this->isSchemaChange($cql)) {
            $this->columnMeta = null;
        }
        try {
            if ($arguments === []) {
                $result = $this->session->execute(new SimpleStatement($cql), []);
            } else {
                $result = $this->session->execute($this->session->prepare($cql, []), ['arguments' => $arguments]);
            }
        } catch (Throwable $e) {
            throw new DatabaseQueryExecuteException(
                sprintf('%s. Query %s fails', $e->getMessage(), $cql),
                (int)$e->getCode(),
                $e
            );
        }
        return $this->rowsToArray($result);
    }

    /**
     * @param mixed $result
     * @return array<array<string, mixed>>
     */
    private function rowsToArray($result): array
    {
        $rows = [];
        $page = $result;
        while ($page instanceof Rows) {
            foreach ($page as $row) {
                $rows[] = array_map([$this, 'createPhpValue'], $row);
            }
            if ($page->isLastPage()) {
                break;
            }
            $page = $page->nextPage(null);
        }
        return $rows;
    }

    private function isSchemaChange(string $cql): bool
    {
        return (bool)preg_match('/^\s*(CREATE|ALTER|DROP)\s/i', $cql);
    }

    /**
     * @param array<string, mixed> $conditions
     */
    private function createWhere(array $conditions = [], string $where = ''): string
    {
        if (empty($conditions) && $where === '') {
            return '';
        }
        if (empty($conditions)) {
            return sprintf(' WHERE %s', $where);
        }
        $cond = [];
        foreach ($conditions as $key => $value) {
            $cond[] = $this->addCondition($key, $value);
        }
        return sprintf(' WHERE %s', implode(' AND ', $cond) . ($where ? ' AND ' . $where : ''));
    }

    /**
     * @param mixed $value
     */
    private function addCondition(string $key, $value): string
    {
        ['columnName' => $columnName, 'operator' => $operator] = $this->splitColumnNameAndOperator($key, $value);
        if (is_array($value)) {
            $inConditions = [];
            foreach (array_keys($value) as $index) {
                $inConditions[] = ':where_' . $index . '_' . $columnName;
            }
            return $this->escapeString($columnName) . ' ' . $operator . ' (' . implode(', ', $inConditions) . ')';
        }
        return $this->escapeString($columnName) . ' ' . $operator . ' :where_' . $columnName;
    }

    /**
     * @param mixed $value
     * @return array{'columnName': string, 'operator': string}
     */
    private function splitColumnNameAndOperator(string $key, $value): array
    {
        $columnName = $key;
        $operator = '=';
        if (preg_match('/^(.*) (=|!=|<>|<|<=|>|>=)$/', $key, $matches) === 1) {
            $columnName = $matches[1];
            $operator = $matches[2];
        }
        $columnName = trim($columnName);

        if ($value === null) {
            throw new UnexpectedValueException('CQL cannot compare a column to NULL, column "' . $columnName . '"');
        }
        if (is_array($value)) {
            if ($operator !== '=') {
                throw new UnexpectedValueException('Cannot accept "' . $operator . '" operator for list value');
            }
            return ['columnName' => $columnName, 'operator' => 'IN'];
        }
        if (in_array($operator, ['!=', '<>'], true)) {
            throw new UnexpectedValueException('CQL does not support the "' . $operator . '" operator, column "' . $columnName . '"');
        }
        return ['columnName' => $columnName, 'operator' => $operator];
    }

    /**
     * @param string[] $groups
     */
    private function createGroup(array $groups = []): string
    {
        return empty($groups) ? '' : sprintf(' GROUP BY %s', implode(', ', $groups));
    }

    /**
     * only clustering columns can be ordered by the server
     *
     * @param string[]|array<string, string> $orders
     */
    private function createOrder(array $orders, string $table): string
    {
        $listOfOrders = [];
        foreach ($this->normalizeOrders($orders) as $column => $way) {
            if (!$this->isClusteringColumn($table, $column)) {
                continue;
            }
            $listOfOrders[] = $this->escapeString($column) . ' ' . $way;
        }
        return empty($listOfOrders) ? '' : sprintf(' ORDER BY %s', implode(', ', $listOfOrders));
    }

    /**
     * @param string[]|array<string, string> $orders
     * @return array<string, string>
     */
    private function ordersToSortInPhp(array $orders, string $table): array
    {
        $toSort = [];
        foreach ($this->normalizeOrders($orders) as $column => $way) {
            if (!$this->isClusteringColumn($table, $column)) {
                $toSort[$column] = $way;
            }
        }
        return $toSort;
    }

    /**
     * @param string[]|array<string, string> $orders
     * @return array<string, string>
     */
    private function normalizeOrders(array $orders): array
    {
        $normalized = [];
        foreach ($orders as $column => $way) {
            if (!in_array(strtoupper((string)$way), ['ASC', 'DESC'], true)) {
                $column = $way;
                $way = 'ASC';
            }
            $normalized[(string)$column] = strtoupper((string)$way);
        }
        return $normalized;
    }

    /**
     * @param array<array<string, mixed>> $rows
     * @param array<string, string> $orders
     * @return array<array<string, mixed>>
     */
    private function sortRows(array $rows, array $orders): array
    {
        if ($orders === []) {
            return $rows;
        }
        usort($rows, function (array $a, array $b) use ($orders): int {
            foreach ($orders as $column => $way) {
                $comparison = $a[$column] <=> $b[$column];
                if ($comparison !== 0) {
                    return $way === 'DESC' ? -$comparison : $comparison;
                }
            }
            return 0;
        });
        return $rows;
    }

    private function isClusteringColumn(string $table, string $column): bool
    {
        return ($this->getColumnMeta()[$table][$column]['kind'] ?? null) === self::KIND_CLUSTERING;
    }

    private function createLimit(?string $limit = null): string
    {
        if (!$limit) {
            return '';
        }
        // CQL knows no OFFSET
        return sprintf(' LIMIT %d', (int)$limit);
    }

    /**
     * @param array<mixed> $data
     */
    private function isMulti(array $data): bool
    {
        if ($data === []) {
            return false;
        }
        foreach ($data as $item) {
            if (!is_array($item)) {
                return false;
            }
        }
        return true;
    }

    /**
     * multiple rows are inserted in one logged batch
     *
     * @param array<array<string, mixed>> $data
     */
    private function buildBatchInsertQuery(string $table, array $data): CassandraStatement
    {
        $queries = [];
        $arguments = [];
        $columns = [];
        foreach ($data as $index => $item) {
            $keys = array_keys($item);
            $queries[] = sprintf(
                'INSERT INTO %s (%s) VALUES (%s);',
                $this->escapeString($table),
                implode(', ', array_map([$this, 'escapeString'], $keys)),
                implode(', ', array_map(fn(string $key): string => ':item_' . $index . '_' . $key, $keys))
            );
            foreach ($item as $key => $value) {
                $arguments['item_' . $index . '_' . $key] = $value;
                $columns['item_' . $index . '_' . $key] = (string)$key;
            }
        }
        return new CassandraStatement(
            'BEGIN BATCH ' . implode(' ', $queries) . ' APPLY BATCH;',
            $arguments,
            $table,
            $columns
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveArguments(CassandraStatement $statement): array
    {
        $table = $statement->getTable();
        if ($table === null) {
            return $statement->getArguments();
        }
        return $this->createRealValues($table, $statement->getArguments(), $statement->getArgumentColumns());
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, string> $argumentColumns
     * @return array<string, mixed>
     */
    private function createRealValues(string $table, array $arguments, array $argumentColumns): array
    {
        $values = [];
        foreach ($arguments as $key => $value) {
            $column = $argumentColumns[$key] ?? $key;
            $values[$key] = $this->createRealValue($value, $this->getColumnType($table, $column));
        }
        return $values;
    }

    /**
     * @param array<string, mixed> $conditions
     * @return array{arguments: array<string, mixed>, columns: array<string, string>}
     */
    private function createConditionValues(array $conditions): array
    {
        $arguments = [];
        $columns = [];
        foreach ($conditions as $key => $condition) {
            ['columnName' => $columnName] = $this->splitColumnNameAndOperator($key, $condition);
            if (!is_array($condition)) {
                $arguments['where_' . $columnName] = $condition;
                $columns['where_' . $columnName] = $columnName;
                continue;
            }
            foreach ($condition as $index => $item) {
                $arguments['where_' . $index . '_' . $columnName] = $item;
                $columns['where_' . $index . '_' . $columnName] = $columnName;
            }
        }
        return ['arguments' => $arguments, 'columns' => $columns];
    }

    /**
     * the driver is strict about value types, so PHP values are wrapped according to the column type
     *
     * @param mixed $value
     * @return mixed
     */
    private function createRealValue($value, ?string $cqlType)
    {
        if ($value === null || $value instanceof Value) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return new Timestamp($value->getTimestamp(), (int)$value->format('u'));
        }
        if ($cqlType === null) {
            return $value;
        }
        if (is_array($value)) {
            return $this->createCollectionValue($value, $cqlType);
        }
        switch ($this->baseType($cqlType)) {
            case 'bigint':
            case 'counter':
                return new Bigint((int)$value);
            case 'smallint':
                return new Smallint((int)$value);
            case 'tinyint':
                return new Tinyint((int)$value);
            case 'float':
                return new Cassandra\Float((float)$value);
            case 'double':
                return (float)$value;
            case 'int':
                return (int)$value;
            case 'boolean':
                return (bool)$value;
            case 'timestamp':
                return new Timestamp($this->toTimestamp($value), 0);
            case 'date':
                // the driver truncates a date to the UTC day, so it has to be built in UTC too
                return new Date($this->toTimestamp($value, true));
            case 'uuid':
                return new Uuid((string)$value);
            case 'timeuuid':
                return is_numeric($value) ? new Timeuuid((int)$value) : $value;
            case 'inet':
                return new Inet((string)$value);
            case 'blob':
                return new Blob((string)$value);
            case 'ascii':
            case 'text':
            case 'varchar':
                return (string)$value;
            default:
                // decimal, varint, time and duration have no lossless conversion from a PHP scalar,
                // pass them through so a Cassandra\Value built by the caller keeps working
                return $value;
        }
    }

    /**
     * @param mixed $value
     */
    private function toTimestamp($value, bool $utc = false): int
    {
        if (is_numeric($value)) {
            return (int)$value;
        }
        $timestamp = $utc ? strtotime((string)$value . ' UTC') : strtotime((string)$value);
        if ($timestamp === false) {
            throw new InvalidArgumentException('Value "' . (string)$value . '" cannot be converted to a timestamp');
        }
        return $timestamp;
    }

    /**
     * @param array<mixed> $value
     * @return mixed
     */
    private function createCollectionValue(array $value, string $cqlType)
    {
        $arguments = $this->collectionArguments($cqlType);
        if ($arguments === []) {
            return $value;
        }
        if ($this->baseType($cqlType) === 'map') {
            $keyType = $arguments[0];
            $valueType = $arguments[1] ?? 'text';
            /** @var Map $map */
            $map = Type::map($this->driverType($keyType), $this->driverType($valueType))->create();
            foreach ($value as $key => $item) {
                // Map::set() mutates the map in place
                $map->set($this->createRealValue($key, $keyType), $this->createRealValue($item, $valueType));
            }
            return $map;
        }
        $items = array_map(fn($item) => $this->createRealValue($item, $arguments[0]), array_values($value));
        $type = $this->baseType($cqlType) === 'set'
            ? Type::set($this->driverType($arguments[0]))
            : Type::collection($this->driverType($arguments[0]));
        return $type->create(...$items);
    }

    private function driverType(string $cqlType): Type
    {
        $baseType = $this->baseType($cqlType);
        $arguments = $this->collectionArguments($cqlType);
        if ($baseType === 'set' && $arguments !== []) {
            return Type::set($this->driverType($arguments[0]));
        }
        if ($baseType === 'list' && $arguments !== []) {
            return Type::collection($this->driverType($arguments[0]));
        }
        if ($baseType === 'map' && count($arguments) > 1) {
            return Type::map($this->driverType($arguments[0]), $this->driverType($arguments[1]));
        }
        if (!method_exists(Type::class, $baseType)) {
            return Type::text();
        }
        /** @var Type $type */
        $type = Type::$baseType();
        return $type;
    }

    private function baseType(string $cqlType): string
    {
        $cqlType = trim(str_replace('frozen', '', $cqlType));
        $position = strpos($cqlType, '<');
        return strtolower($position === false ? $cqlType : substr($cqlType, 0, $position));
    }

    /**
     * splits `map<text, int>` into `['text', 'int']`
     *
     * @return string[]
     */
    private function collectionArguments(string $cqlType): array
    {
        $position = strpos($cqlType, '<');
        if ($position === false) {
            return [];
        }
        $inner = substr($cqlType, $position + 1, strrpos($cqlType, '>') - $position - 1);
        $arguments = [];
        $depth = 0;
        $current = '';
        foreach (str_split($inner) as $character) {
            if ($character === ',' && $depth === 0) {
                $arguments[] = trim($current);
                $current = '';
                continue;
            }
            $depth += (int)($character === '<') - (int)($character === '>');
            $current .= $character;
        }
        $arguments[] = trim($current);
        return array_values(array_filter($arguments, fn(string $argument): bool => $argument !== ''));
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function createPhpValue($value)
    {
        if ($value instanceof Timestamp) {
            return date('Y-m-d H:i:s', $value->time());
        }
        if ($value instanceof Date) {
            return gmdate('Y-m-d', $value->seconds());
        }
        if ($value instanceof Cassandra\Float) {
            return (float)(string)$value;
        }
        if ($value instanceof Time) {
            return $value->seconds();
        }
        if ($value instanceof Blob) {
            return $value->toBinaryString();
        }
        if ($value instanceof Map) {
            $map = [];
            foreach ($value->keys() as $key) {
                $map[(string)$this->createPhpValue($key)] = $this->createPhpValue($value->get($key));
            }
            return $map;
        }
        if ($value instanceof Collection || $value instanceof Cassandra\Set || $value instanceof Tuple || $value instanceof UserTypeValue) {
            return array_map([$this, 'createPhpValue'], $value->values());
        }
        if ($value instanceof Duration) {
            return (string)$value->months() . 'mo' . (string)$value->days() . 'd' . (string)$value->nanos() . 'ns';
        }
        if ($value instanceof Value) {
            return (string)$value;
        }
        return $value;
    }

    private function getColumnType(string $table, string $column): ?string
    {
        return $this->getColumnMeta()[$table][$column]['type'] ?? null;
    }

    /**
     * @return array<string, array<string, array{type: string, kind: string}>>
     */
    private function getColumnMeta(): array
    {
        if ($this->columnMeta === null) {
            $this->columnMeta = [];
            foreach ($this->run(sprintf(
                "SELECT table_name, column_name, type, kind FROM system_schema.columns WHERE keyspace_name = '%s'",
                $this->escapeLiteral($this->keyspace)
            )) as $row) {
                $this->columnMeta[(string)$row['table_name']][(string)$row['column_name']] = [
                    'type' => (string)$row['type'],
                    'kind' => (string)$row['kind'],
                ];
            }
        }
        return $this->columnMeta;
    }

    /**
     * @return array<string, array<array<string, mixed>>> table name => primary key columns
     */
    private function loadPrimaryKeyColumns(string $database): array
    {
        $columns = $this->run(sprintf(
            "SELECT table_name, column_name, kind, position, clustering_order FROM system_schema.columns WHERE keyspace_name = '%s'",
            $this->escapeLiteral($database)
        ));

        $tableNames = [];
        foreach ($this->run(sprintf(
            "SELECT table_name FROM system_schema.tables WHERE keyspace_name = '%s'",
            $this->escapeLiteral($database)
        )) as $table) {
            $tableNames[(string)$table['table_name']] = true;
        }

        $primaryColumns = [];
        foreach ($columns as $column) {
            $tableName = (string)$column['table_name'];
            if (!isset($tableNames[$tableName])) {
                continue;
            }
            if (!in_array($column['kind'], [self::KIND_PARTITION_KEY, self::KIND_CLUSTERING], true)) {
                continue;
            }
            $primaryColumns[$tableName][] = $column;
        }
        return $primaryColumns;
    }

    /**
     * builds index columns of the synthetic PRIMARY index in the CQL order:
     * partition key columns first, clustering columns after them
     *
     * @param array<array<string, mixed>> $primaryColumns
     * @return IndexColumn[]
     */
    private function createPrimaryIndexColumns(array $primaryColumns): array
    {
        usort($primaryColumns, function (array $a, array $b): int {
            if ($a['kind'] !== $b['kind']) {
                return $a['kind'] === self::KIND_PARTITION_KEY ? -1 : 1;
            }
            return (int)$a['position'] <=> (int)$b['position'];
        });

        $partitionKeyColumns = [];
        $clusteringColumns = [];
        foreach ($primaryColumns as $primaryColumn) {
            $name = (string)$primaryColumn['column_name'];
            if ($primaryColumn['kind'] === self::KIND_PARTITION_KEY) {
                $partitionKeyColumns[] = $name;
                continue;
            }
            $order = strtoupper((string)$primaryColumn['clustering_order']);
            $clusteringColumns[] = new IndexColumn($order === 'DESC' ? $name . ' DESC' : $name);
        }

        $partitionKey = count($partitionKeyColumns) > 1
            ? '(' . implode(', ', $partitionKeyColumns) . ')'
            : (string)($partitionKeyColumns[0] ?? '');

        return array_merge([new IndexColumn($partitionKey)], $clusteringColumns);
    }

    /**
     * `keys(tags)`, `values(tags)`, `entries(tags)` and `full(tags)` all index the column `tags`
     */
    private function parseIndexTarget(string $target): ?string
    {
        if ($target === '') {
            return null;
        }
        if (preg_match('/^(?:keys|values|entries|full)\((.*)\)$/i', $target, $matches) === 1) {
            return trim($matches[1], '"');
        }
        return trim($target, '"');
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private function remapType(string $cqlType): array
    {
        $baseType = $this->baseType($cqlType);
        $types = [
            'ascii' => Column::TYPE_ASCII,
            'bigint' => Column::TYPE_BIG_INTEGER,
            'blob' => Column::TYPE_BLOB,
            'boolean' => Column::TYPE_BOOLEAN,
            'counter' => Column::TYPE_COUNTER,
            'date' => Column::TYPE_DATE,
            'decimal' => Column::TYPE_DECIMAL,
            'double' => Column::TYPE_DOUBLE,
            'duration' => Column::TYPE_DURATION,
            'float' => Column::TYPE_FLOAT,
            'inet' => Column::TYPE_INET,
            'int' => Column::TYPE_INTEGER,
            'smallint' => Column::TYPE_SMALL_INTEGER,
            'text' => Column::TYPE_STRING,
            'time' => Column::TYPE_TIME,
            'timestamp' => Column::TYPE_DATETIME,
            'timeuuid' => Column::TYPE_TIMEUUID,
            'tinyint' => Column::TYPE_TINY_INTEGER,
            'uuid' => Column::TYPE_UUID,
            'varchar' => Column::TYPE_STRING,
            'varint' => Column::TYPE_VARINT,
        ];
        if (isset($types[$baseType])) {
            return [$types[$baseType], []];
        }

        $arguments = $this->collectionArguments($cqlType);
        if ($baseType === 'set') {
            return [Column::TYPE_SET, [ColumnSettings::SETTING_VALUES => $arguments]];
        }
        if ($baseType === 'list') {
            return [Column::TYPE_LIST, [ColumnSettings::SETTING_VALUES => $arguments]];
        }
        if ($baseType === 'map') {
            return [Column::TYPE_MAP, [ColumnSettings::SETTING_VALUES => $arguments]];
        }
        if ($baseType === 'tuple') {
            return [Column::TYPE_TUPLE, [ColumnSettings::SETTING_VALUES => $arguments]];
        }
        return [Column::TYPE_STRING, []];
    }

    private function escapeLiteral(string $literal): string
    {
        return str_replace("'", "''", $literal);
    }
}
