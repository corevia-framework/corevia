<?php

declare(strict_types=1);

// Optional adapter check, separate from the database-free learning tests.
require dirname(__DIR__) . '/autoload.php';

$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE learning_tasks (id VARCHAR(21) PRIMARY KEY, title VARCHAR(120) NOT NULL, completed INTEGER NOT NULL DEFAULT 0, created_at VARCHAR(32) NOT NULL)');
$tasks = new \Learning\Tasks\PdoTaskRepository($pdo);
$task = $tasks->create("Learn prepared statements: ' ; DROP TABLE learning_tasks; --");
if ($tasks->all()[0]['title'] !== $task['title'] || $tasks->all()[0]['completed'] !== false) {
    throw new RuntimeException('PDO create/list failed.');
}
if ($tasks->complete($task['id'])['completed'] !== true || $tasks->complete($task['id'])['completed'] !== true) {
    throw new RuntimeException('PDO completion failed.');
}
if ($tasks->complete('missing') !== null || count($tasks->all()) !== 1) {
    throw new RuntimeException('PDO parameter handling failed.');
}
echo "PASS optional PDO adapter (in-memory SQLite); MySQL server was not tested.\n";
