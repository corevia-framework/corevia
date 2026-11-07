# Database-free catalog API

This development example uses JSON documents and private JSON identities. It requires PHP 8.2+; no database, Node, mail or model provider is needed. It refuses `APP_ENV=production`. It is a tutorial, not a hosted administration tool.

From the repository root:

```sh
php -S 127.0.0.1:8081 examples/v4/catalog/index.php
```

In another terminal, set `CATALOG_DEMO_PASSWORD` to a local development password of at least 12 characters, then run `php examples/v4/catalog/token.php`. The CLI creates a demo identity, grants catalog permissions, and prints a bearer credential once. Keep it private. Use `Authorization: Bearer <token>` for `GET /api/v1/items`. Create with `POST /api/v1/items`, JSON `{"title":"Learn Corevia"}`, and a unique `Idempotency-Key` header. Delete using `DELETE /api/v1/items/<returned-id>`. `/openapi.json` documents the routes. Stop the server with Ctrl+C.

State lives in ignored `storage/catalog-reference/`. Each identity can read/delete only its own items. Repeating a create with the same key and input returns its recorded result; different input returns 409. A crash between the data write and recording the result requires operator reconciliation; this example does not claim an atomic transaction across separate identity and catalog stores.

## SQL variant

The bootstrap closure also accepts a `Corevia\Persistence\SqlConnection` as its second argument. Create the `catalog_items` table using the application migration below before injecting that connection. The SQL and document implementations intentionally share an API contract, not a fake common SQL engine. SQLite runs in local acceptance; MySQL/PostgreSQL acceptance is wired to disposable CI services. Never point tests at a live application database.

```php
$db = Corevia\Persistence\SqlConnection::connect([
    'driver' => 'sqlite', 'database' => __DIR__.'/private.sqlite',
]);
(new Corevia\Persistence\MigrationRunner($db))->up(__DIR__.'/migrations');
$start = require __DIR__.'/bootstrap.php';
$runtime = $start(__DIR__.'/private-state', $db);
```

Use private absolute paths in a real application, outside the document root. PostgreSQL/MySQL connection details come from private configuration/environment variables; see the framework database guide. Do not copy passwords into routes or public assets.

Run `php tests/v4/catalog.php` for JSON/SQLite contracts and `php tests/v4/data.php` for migration/query contracts. The migration's `down` drops application data; restore matching code, configuration and data backups for a real rollback.
