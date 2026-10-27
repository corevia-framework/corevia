<?php
declare(strict_types=1);
namespace Corevia\Persistence;

/** Named lazy connections. Configuration alone never contacts a server. */
final class DatabaseManager
{
    private array $connections = [];
    private array $connectors = [];
    public function __construct(private readonly array $configuration) {}
    public function extend(string $driver, callable $connector): void { $this->connectors[$driver] = \Closure::fromCallable($connector); }
    public function connection(?string $name = null): SqlConnection|DocumentStore
    {
        $name ??= $this->configuration['default'] ?? 'local';
        if (isset($this->connections[$name])) { return $this->connections[$name]; }
        $config = $this->configuration['connections'][$name] ?? throw new \InvalidArgumentException('Unknown database connection: ' . $name);
        $driver = $config['driver'] ?? '';
        if (isset($this->connectors[$driver])) { $connection = ($this->connectors[$driver])($config); }
        else {
            $connection = match ($driver) {
                'sqlite', 'mysql', 'pgsql', 'pdo' => SqlConnection::connect($config),
                'json' => new JsonDocumentStore($config['path']),
                'mongodb' => new MongoDocumentStore($config['uri'], $config['database'], $config['options'] ?? []),
                default => throw new \InvalidArgumentException('Unsupported database driver: ' . $driver),
            };
        }
        if (!$connection instanceof SqlConnection && !$connection instanceof DocumentStore) { throw new \LogicException('Connector must return SqlConnection or DocumentStore.'); }
        return $this->connections[$name] = $connection;
    }
    public function sql(?string $name = null): SqlConnection
    {
        $connection = $this->connection($name);
        return $connection instanceof SqlConnection ? $connection : throw new \LogicException('Select a SQL connection.');
    }
    public function documents(?string $name = null): DocumentStore
    {
        $connection = $this->connection($name);
        return $connection instanceof DocumentStore ? $connection : throw new \LogicException('Select a document connection.');
    }
    public function configured(): array
    {
        $result=[];
        foreach ($this->configuration['connections'] ?? [] as $name=>$config) { $result[$name]=['driver'=>$config['driver'] ?? 'unknown','connected'=>isset($this->connections[$name])]; }
        return $result;
    }
    public function disconnect(?string $name = null): void { if ($name===null) { $this->connections=[]; } else { unset($this->connections[$name]); } }
}
