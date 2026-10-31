<?php
declare(strict_types=1);
namespace Corevia\Persistence;

final class SqlConnection
{
    public function __construct(private readonly \PDO $pdo, private readonly ?QueryDiagnostics $diagnostics = null)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        if (in_array($this->driver(), ['mysql','pgsql'], true)) { $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false); }
    }
    public static function dsn(array $config): string
    {
        $driver=$config['driver'] ?? '';
        if ($driver==='pdo') {
            if (!is_string($config['dsn'] ?? null) || !preg_match('/^[a-z0-9_]+:.+/Di',$config['dsn'])) { throw new \InvalidArgumentException('A PDO DSN is required.'); }
            return $config['dsn'];
        }
        if ($driver==='sqlite') { return 'sqlite:' . ($config['database'] ?? ':memory:'); }
        if (!in_array($driver,['mysql','pgsql'],true)) { throw new \InvalidArgumentException('Unsupported SQL driver.'); }
        $host=$config['host'] ?? '127.0.0.1'; $database=$config['database'] ?? '';
        foreach ([$host,$database,$config['charset'] ?? 'utf8mb4',$config['sslmode'] ?? 'prefer'] as $value) {
            if (!is_string($value) || preg_match('/[;\x00-\x20]/',$value)) { throw new \InvalidArgumentException('Invalid DSN component.'); }
        }
        $port=filter_var($config['port'] ?? ($driver==='mysql'?3306:5432),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
        if ($port===false || $database==='') { throw new \InvalidArgumentException('Database name and valid port required.'); }
        $dsn="$driver:host=$host;port=$port;dbname=$database";
        return $dsn . ($driver==='mysql' ? ';charset='.($config['charset'] ?? 'utf8mb4') : ';sslmode='.($config['sslmode'] ?? 'prefer'));
    }
    public static function connect(array $config): self
    {
        if (!class_exists(\PDO::class)) { throw new \RuntimeException('Enable PDO and the selected database driver.'); }
        $dsn=self::dsn($config); $driver=strtok($dsn,':');
        if (!in_array($driver,\PDO::getAvailableDrivers(),true)) { throw new \RuntimeException('Enable the PDO driver: '.$driver); }
        try { return new self(new \PDO($dsn,$config['username'] ?? null,$config['password'] ?? null,$config['options'] ?? [])); }
        catch (\PDOException) { throw new \RuntimeException('Database connection failed. Check private connection settings and server availability.'); }
    }
    public function pdo(): \PDO { return $this->pdo; }
    public function inTransaction(): bool { return $this->pdo->inTransaction(); }
    public function driver(): string { return $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME); }
    public function execute(string $sql, array $bindings=[]): \PDOStatement
    {
        $start=hrtime(true);
        try {
            $statement=$this->pdo->prepare($sql);
            foreach ($bindings as $key=>$value) {
                $type=match(true){is_int($value)=>\PDO::PARAM_INT,is_bool($value)=>\PDO::PARAM_BOOL,$value===null=>\PDO::PARAM_NULL,default=>\PDO::PARAM_STR};
                $statement->bindValue(is_int($key)?$key+1:$key,$value,$type);
            }
            $statement->execute(); return $statement;
        } finally { $this->diagnostics?->record($sql,(hrtime(true)-$start)/1e6); }
    }
    public function select(string $sql, array $bindings=[]): array { return $this->execute($sql,$bindings)->fetchAll(); }
    public function table(string $table): Query { return new Query($this,$table); }
    public function quoteIdentifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$name)) { throw new \InvalidArgumentException('Invalid SQL identifier.'); }
        $quote=match($this->driver()){'mysql'=>'`','sqlite','pgsql'=>'"',default=>throw new \LogicException('Use raw prepared SQL for this PDO driver.')};
        return $quote.$name.$quote;
    }
    public function transaction(callable $work): mixed
    {
        $nested=$this->pdo->inTransaction(); $savepoint='cv_'.bin2hex(random_bytes(8));
        if ($nested) { $this->pdo->exec('SAVEPOINT '.$savepoint); } else { $this->pdo->beginTransaction(); }
        try { $result=$work($this); if ($nested) { $this->pdo->exec('RELEASE SAVEPOINT '.$savepoint); } else { $this->pdo->commit(); } return $result; }
        catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { if ($nested) { $this->pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint); $this->pdo->exec('RELEASE SAVEPOINT '.$savepoint); } else { $this->pdo->rollBack(); } }
            throw $error;
        }
    }
}
