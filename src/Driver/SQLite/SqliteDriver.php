<?php

namespace Phore\MiniSql\Driver\SQLite;

use Phore\MiniSql\Driver\OrmDriver;
use Phore\MiniSql\Driver\OrmSchemaUpdater;

class SqliteDriver implements OrmDriver
{
    public readonly \PDO $pdo;

    public function __construct(
        \PDO|string $pdo,
        private ?string $dsn = null
    ) {
        if (is_string($pdo)) {
            if ( ! str_starts_with($pdo, "sqlite:"))
                throw new \InvalidArgumentException("Invalid DSN: '$pdo' - Must start with 'sqlite:'.");
            $this->dsn = $pdo;
            return;
        }

        $this->pdo = $pdo;
    }

    #[\Override] public function connect(): \PDO
    {
        if (isset($this->pdo))
            return $this->pdo;

        $this->pdo = new \PDO($this->dsn);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("PRAGMA foreign_keys = ON");
        return $this->pdo;
    }

    #[\Override] public function getSchemaUpdater(): OrmSchemaUpdater
    {
        return new SqliteSchemaUpdater($this->connect());
    }
}

