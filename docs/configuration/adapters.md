## Adapters

You can setup connection to database in environments block of config file. There are several ways to do so:

#### MySQL
You can use full config array:
```php
'environments' => [
    'env1' => [
        'adapter' => 'mysql',
        'db_name' => 'phoenix',
        'host' => 'localhost',
        'username' => 'root',
        'password' => '123',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_general_ci',
    ],
],
```
or dsn:
```php
'environments' => [
    'env2' => [
        'adapter' => 'mysql',
        'dsn' => 'mysql:dbname=phoenix;host=localhost;charset=utf8mb4',
        'username' => 'root',
        'password' => '123',
    ],
],
```

#### PostgreSQL
This is similar to MySQL. You can use full config array:
```php
'environments' => [
    'env3' => [
        'adapter' => 'pgsql',
        'db_name' => 'phoenix',
        'host' => 'localhost',
        'username' => 'postgres',
        'password' => '123',
        'charset' => 'utf8',
    ],
],
```
or dsn:
```php
'environments' => [
    'env4' => [
        'adapter' => 'pgsql',
        'dsn' => 'pgsql:dbname=phoenix;host=localhost;options=\'--client_encoding=utf8\'',
        'username' => 'postgres',
        'password' => '123',
    ],
],
```

#### Cassandra / ScyllaDB
This adapter talks CQL and needs the [`cassandra`](https://github.com/datastax/php-driver) PHP extension (DataStax PHP driver). There is no dsn, `db_name` is the keyspace and it has to exist before migrations are run.
```php
'environments' => [
    'env5' => [
        'adapter' => 'cassandra',
        'db_name' => 'phoenix',            // keyspace
        'host' => 'node1,node2,node3',     // one or more contact points, separated by comma
        'port' => 9042,                    // optional, default 9042
        'username' => 'cassandra',         // optional
        'password' => '123',               // optional
        'consistency' => 'local_quorum',   // optional, one of the Cassandra::CONSISTENCY_* levels
        'page_size' => 5000,               // optional
        'connect_timeout' => 5.0,          // optional, seconds
        'request_timeout' => 12.0,         // optional, seconds
        'datacenter' => 'dc1',             // optional, enables datacenter aware routing
        'ssl' => true,                     // optional
        'ssl_trusted_certs' => '/path/ca.pem', // optional
    ],
],
```

##### Primary keys
Every CQL table needs a primary key. The first primary column is the partition key, the rest are clustering columns:
```php
$this->table('events', ['tenant_id', 'created_at'])
```
A composite partition key is written as one primary column wrapped in brackets, and a clustering column may carry its order:
```php
$this->table('events', ['(tenant_id, day)', 'created_at DESC', 'id'])
```
This produces `PRIMARY KEY ((tenant_id, day), created_at, id) WITH CLUSTERING ORDER BY (created_at DESC, id ASC)`.

The primary key of an existing table cannot be changed, so `addPrimary()` / `addPrimaryColumns()` in an `alter` migration and `renameTable()` are rejected. In CQL only primary key columns can be renamed.

##### Column types
On top of the common types this adapter accepts `ascii`, `varint`, `counter`, `inet`, `timeuuid`, `duration`, `list`, `map` and `tuple`. Collections take their element types from the `values` setting:
```php
$table->addColumn('tags', Column::TYPE_SET, ['values' => ['text']]);      // set<text>
$table->addColumn('scores', Column::TYPE_LIST, ['values' => ['int']]);    // list<int>
$table->addColumn('meta', Column::TYPE_MAP, ['values' => ['text', 'int']]); // map<text,int>
```
`string`, `char` and the `text` family all become `text`, `datetime` and `timestamp` become `timestamp`, and the `blob` / `binary` family becomes `blob`.

##### What CQL cannot do
These are rejected with an explanatory exception instead of producing a query which would fail on the server:
- autoincrement columns (use `uuid` or `timeuuid`), column default values
- foreign keys, unique constraints and unique / fulltext indexes
- renaming a table, renaming a non primary key column, changing a primary key
- a secondary index over more than one column

Further differences worth knowing:
- **there are no transactions**, so a migration on Cassandra is not atomic - a failing migration leaves the already executed statements applied
- `view()` creates a **materialized view**; the sql you pass is everything after `AS`, including the `PRIMARY KEY` part. `replaceView()` runs a drop followed by a create
- `fetchAll()` can only let the server order by clustering columns; ordering by any other column is done in PHP, and `LIMIT` is then applied after that sorting. CQL has no `OFFSET`
- `decimal`, `varint`, `time` and `duration` values have no lossless conversion from a PHP scalar - pass a `Cassandra\Decimal`, `Cassandra\Varint`, `Cassandra\Time` or `Cassandra\Duration` instance for those columns
- the log table is keyed by `(migration_datetime, classname)` rather than by a generated id
- the `dump` and `diff` commands do not write the primary key into the generated migration (a pre-existing limitation of `Table::toMigrationTable()`, which affects every adapter). On Cassandra a table cannot be created without one, so the primary key has to be added to the generated migration by hand
