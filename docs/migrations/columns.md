## Columns

### Remapping types in adapters
There are several types in Phoenix, but not all adapters allows all types. This table shows how types are implemented in adapters.

| Type          | MySQL      | PostgreSQL    | Cassandra   |
|---------------|------------|---------------|-------------|
| tinyinteger   | tinyint    | int2          | tinyint     |
| smallinteger  | smallint   | int2          | smallint    |
| mediuminteger | mediumint  | int4          | int         |
| integer       | int        | int4          | int         |
| biginteger    | bigint     | int8          | bigint      |
| numeric       | decimal    | numeric       | decimal     |
| decimal       | decimal    | numeric       | decimal     |
| float         | float      | float4        | float       |
| double        | double     | float8        | double      |
| binary        | binary     | bytea         | blob        |
| varbinary     | varbinary  | bytea         | blob        |
| char          | char       | char          | text        |
| string        | varchar    | varchar       | text        |
| boolean       | tinyint(1) | bool          | boolean     |
| bit           | bit        | bit           | varint      |
| date          | date       | date          | date        |
| time          | time       | time          | time        |
| datetime      | datetime   | timestamp(6)  | timestamp   |
| timestamp     | timestamp  | timestamp(6)  | timestamp   |
| timestamptz   | -          | timestamptz   | timestamp   |
| year          | year       | numeric(4)    | smallint    |
| tinytext      | tinytext   | text          | text        |
| mediumtext    | mediumtext | text          | text        |
| text          | text       | text          | text        |
| longtext      | longtext   | text          | text        |
| tinyblob      | tinyblob   | bytea         | blob        |
| mediumblob    | mediumblob | bytea         | blob        |
| blob          | blob       | bytea         | blob        |
| longblob      | longblob   | bytea         | blob        |
| uuid          | char(36)   | uuid          | uuid        |
| json          | text/json* | json          | text        |
| enum          | enum       | USER-DEFINED  | text        |
| set           | set        | ARRAY         | set<text>** |
| point         | point      | point         | text        |
| line          | linestring | line          | text        |
| polygon       | polygon    | polygon       | text        |

* Depends on version of MySQL (text for < 5.7.8, json for >= 5.7.8)

** `set<text>` unless a single CQL type name is given in the `values` setting, e.g. `['int']` produces `set<int>`

### Adapter specific types
These types are only supported by some adapters, using them elsewhere produces an invalid query:

| Type     | Cassandra | MySQL | PostgreSQL |
|----------|-----------|-------|------------|
| ascii    | ascii     | -     | -          |
| varint   | varint    | -     | -          |
| counter  | counter   | -     | -          |
| inet     | inet      | -     | inet       |
| timeuuid | timeuuid  | -     | -          |
| duration | duration  | -     | -          |
| list     | list<T>   | -     | -          |
| map      | map<K,V>  | -     | -          |
| tuple    | tuple<T,> | -     | -          |

The element types of `list`, `map` and `tuple` are taken from the `values` setting:
```php
$table->addColumn('scores', Column::TYPE_LIST, ['values' => ['int']]);      // list<int>
$table->addColumn('meta', Column::TYPE_MAP, ['values' => ['text', 'int']]); // map<text,int>
$table->addColumn('pair', Column::TYPE_TUPLE, ['values' => ['text', 'int']]); // tuple<text,int>
```
