<?php

declare(strict_types=1);

// Optional connection factory. Nothing includes this in the default JSON-file app.
return static function (): PDO {
    $dsn = getenv('COREVIA_DB_DSN');
    $user = getenv('COREVIA_DB_USER');
    $password = getenv('COREVIA_DB_PASSWORD');
    if (!$dsn || $user === false || $password === false) {
        throw new RuntimeException('Set COREVIA_DB_DSN, COREVIA_DB_USER and COREVIA_DB_PASSWORD.');
    }
    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
};
