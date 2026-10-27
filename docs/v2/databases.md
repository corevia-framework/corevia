# Connecting databases

Corevia provides named, lazy connections through `Corevia\Persistence\DatabaseManager`. The default `local` connection is a private JSON document store. The starter homepage and routes work with `php -n`, without PDO, database servers or MongoDB installed.

The manager is registered in both application bootstraps. Inject it into your controller or action:

```php
use Corevia\Persistence\DatabaseManager;

final class UsersController
{
    public function __construct(private DatabaseManager $db) {}

    public function index(): array
    {
        return $this->db->sql('mysql')->table('users')
            ->where('active', 1)->orderBy('name')->limit(20)->get();
    }
}
```

## Configure SQL

Edit `config/settings/database.php` or set environment variables before starting PHP. `.env.example` lists the supported names; it is a template, not an automatically loaded dotenv file.

```powershell
$env:DB_CONNECTION = 'mysql'  # use 'pgsql' for PostgreSQL
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '3306'        # PostgreSQL: 5432
$env:DB_DATABASE = 'myapp'
$env:DB_USERNAME = 'myapp_user'
$env:DB_PASSWORD = '<your local password>'
php corevia cache:clear
php corevia db:check mysql
php corevia serve
```

For PostgreSQL, enable `pdo_pgsql`; for MySQL/MariaDB, enable `pdo_mysql`. SQLite uses `pdo_sqlite` and the `SQLITE_DATABASE` file path. PDO alone is not a database server or a driver. Create the database and a suitably restricted user first. PostgreSQL's DB_SSLMODE and MySQL's PDO TLS options can configure encrypted server connections. In production, use the TLS and certificate settings appropriate to your database host.

`db:status` lists configured names and installed extensions without contacting a server or printing credentials. `db:check <name>` explicitly opens and checks the connection. Rebuild configuration caches after changing environment settings. Cached configuration can contain credentials; keep storage private with appropriate filesystem permissions.

## Queries and transactions

```php
$connection = $db->sql('pgsql');
$connection->execute(
    'INSERT INTO users (id, name, active) VALUES (?, ?, ?)',
    ['user_1', 'Ada', 1]
);
$user = $connection->table('users')->where('id', 'user_1')->first();
$connection->table('users')->where('id', 'user_1')->update(['name' => 'Grace']);
$connection->transaction(function ($sql) {
    // Use the same connection here. Nested transactions use savepoints.
});
```

The query builder supports select, insert, filtered update/delete, equality/range/LIKE filters, NULL checks, ordering and limits for MySQL, PostgreSQL and SQLite. Query branches are immutable. Identifiers and operators are validated and values are bound separately. This is a small query builder, not Eloquent or a full ORM. Application schemas and domain migrations remain explicit SQL. Raw `execute`/`select` and `pdo()` expose native functionality when needed; never interpolate untrusted values into raw SQL.

Other PDO drivers can use a named `['driver'=>'pdo', 'dsn'=>'sqlsrv:Server=...', 'username'=>..., 'password'=>...]` configuration. Native prepared queries are available; the portable builder and flow adapter support the three tested SQL dialects only. Existing PDO objects can be wrapped with `new SqlConnection($pdo)` and optional query diagnostics.

## Persist flows in SQL

The original SQLite `PdoRunStore` remains compatible. The new portable `SqlRunStore` supports MySQL/InnoDB, PostgreSQL and SQLite with separate `corevia_sql_runs` and `corevia_sql_keys` tables:

```powershell
php corevia db:migrate mysql
$env:RUN_STORE = 'sql'
$env:RUN_DB_CONNECTION = 'mysql'
php corevia cache:clear
```

This command creates only framework run-store tables, not your application tables. It is explicit; normal application boot never migrates the database. `RUN_STORE=json` is the starter default; `memory` is available for ephemeral tests. The web bootstrap reads these settings. The legacy checkout bootstrap keeps its original SQLite ledger, separate from the new application's selected database.

SQL run snapshots include pause/resume checkpoints and compensation/timeline records. Idempotency reservation is transactional; MySQL key columns are binary so case-distinct keys stay distinct. Flow names are limited to 128 bytes and keys to 255 bytes. Exclusive retry/resume locks are persisted. A crashed owner leaves uncertain work locked for operator reconciliation; never clear a lock and replay an external effect blindly.

## NoSQL and database-free storage

```php
$documents = $db->documents('local'); // JSON files, no database extension
$documents->put('notes', 'note_1', ['title' => 'Learn Corevia', 'done' => false]);
$note = $documents->get('notes', 'note_1');
$pending = $documents->find('notes', ['done' => false], limit: 20);
$documents->delete('notes', 'note_1');
```

For MongoDB, enable the optional official PHP `mongodb` extension and set MONGODB_URI and MONGODB_DATABASE, then use `$db->documents('mongodb')`. The adapter uses the native driver, so no MongoDB Composer library is required. Configure authentication/TLS in the URI and driver options; do not commit the URI when it contains credentials.

Both document adapters use explicit string IDs and JSON-compatible values. `put` replaces the document and sets `_id` to the provided ID; it is not a patch. `find` supports top-level scalar equality, with no arbitrary MongoDB operators supplied by a browser. Missing fields do not match explicit null. MongoDB has native numeric equality semantics; local JSON uses strict PHP scalar equality. Limits are 1–1000 results and 1MiB per document. JSON collections are capped at 16MiB and coordinated by local file locks. MongoDB is application document storage; flow persistence uses the selected JSON or SQL RunStore.

Add another NoSQL provider by implementing `DocumentStore` and registering `$db->extend('your-driver', fn(array $config) => new YourDocumentStore($config))`. SQL and document models stay explicit; a NoSQL engine is not presented as SQL. Native transactions or advanced MongoDB queries belong in a dedicated application repository.

## Verification

`php tests/features/databases.php` exercises JSON and SQLite locally. `php -n tests/features/databases.php` verifies the no-database baseline. CI also provisions real MySQL 8.4, PostgreSQL 16 and MongoDB 7 services and runs the same contracts with `COREVIA_DB_TESTS=1`. External contract tests target the dedicated `corevia_ci` database. They create uniquely named application test tables/collections and framework run-store records.

References: [PDO connections](https://www.php.net/manual/en/pdo.connections.php), [MySQL DSNs](https://www.php.net/manual/en/ref.pdo-mysql.connection.php), [MongoDB PHP driver](https://www.php.net/manual/en/set.mongodb.php).
