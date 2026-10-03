# Connecting a database later

You do not need a database to run this project. Its default `bootstrap.php` uses `JsonTaskRepository` and writes tasks to `storage/tasks.json`.

Corevia does not connect a database automatically. Your application's bootstrap creates a PDO connection, then passes a storage service to Corevia's dependency container. Actions receive that service through their constructors.

```text
SaveTask action → TaskRepository interface → JsonTaskRepository → tasks.json
                                        or
                                       → PdoTaskRepository → MySQL table
```

Both repositories implement the same `all()`, `create()` and `complete()` methods. `SaveTask.php` and `CompleteTask.php` do not need to change when you switch storage.

## 1. When MySQL is available

Create a database, for example `corevia_learning`, and an application user with access to it. Ensure your PHP installation enables `pdo_mysql` (`php -m` lists extensions). Import `database/tasks.mysql.sql` into that database using your MySQL client. This creates the `learning_tasks` table.

## 2. Set connection details

From the root Corevia directory, in the terminal that starts the learning app:

```powershell
$env:COREVIA_DB_DSN = 'mysql:host=127.0.0.1;port=3306;dbname=corevia_learning;charset=utf8mb4'
$env:COREVIA_DB_USER = 'your_application_user'
$env:COREVIA_DB_PASSWORD = 'your_password'
```

The DSN tells PDO which driver, host, port, database and charset to use. Keep credentials out of source files. [PHP's PDO MySQL DSN documentation](https://www.php.net/manual/en/ref.pdo-mysql.connection.php) explains these fields.

## 3. Switch the repository in bootstrap

In `test-project/bootstrap.php`, replace:

```php
$tasks = new JsonTaskRepository($storage . '/tasks.json');
```

with:

```php
// Still needed for the file-based application log.
if (!is_dir($storage)) {
    mkdir($storage, 0700, true);
}

$connect = require __DIR__ . '/database/connect.php';
$pdo = $connect();
$tasks = new \Learning\Tasks\PdoTaskRepository($pdo);
```

Leave this line in place:

```php
$app->container->set(TaskRepository::class, $tasks);
```

Restart `php test-project/console.php serve`. New tasks now go into MySQL. Existing JSON tasks are not automatically imported; switching the adapter does not migrate data.

The optional connection factory uses exception mode and native prepared statements. PDO connection failures throw exceptions; the application returns a generic HTTP failure instead of exposing credentials. See [PHP's PDO connection guide](https://www.php.net/manual/en/pdo.connections.php).

## 4. Understand the query

From `PdoTaskRepository.php`:

```php
$query = $this->pdo->prepare(
    'INSERT INTO learning_tasks (id, title, completed, created_at) VALUES (?, ?, 0, ?)'
);
$query->execute([$task['id'], $title, $task['created_at']]);
```

The question marks are placeholders. Values are passed separately, rather than concatenated into SQL. The statement treats a task title as data even when it contains quote characters. [PHP's prepared-statement documentation](https://www.php.net/manual/en/pdo.prepare.php) describes how placeholders work.

For another feature, you can register the PDO connection itself:

```php
$app->container->set(PDO::class, $pdo);
```

Then a service/action with `__construct(private PDO $pdo)` receives that connection. Using a repository keeps database queries together and lets you replace storage more easily.

## Task storage and framework execution storage are separate

| Storage | What it contains | Learning app |
| --- | --- | --- |
| TaskRepository | Your application's tasks | JSON file by default; optional MySQL repository |
| Corevia RunStore | Flow run records, step history, idempotency and retry locks | MemoryRunStore |

Switching TaskRepository to MySQL does **not** persist Corevia run history. The framework's existing `Corevia\Persistence\PdoRunStore` supports **SQLite only**, even though its name contains PDO; passing a MySQL connection to it is unsupported. MySQL run persistence would require a separate RunStore adapter.

We tested the optional task repository against in-memory PDO SQLite to check its CRUD behavior and parameter handling. We did not connect to a MySQL server. The main app and `tests/run.php` remain database-free.
