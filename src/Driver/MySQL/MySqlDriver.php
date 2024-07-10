<?php

namespace Phore\MiniSql\Driver\MySQL;

use Phore\MiniSql\Driver\OrmDriver;
use Phore\MiniSql\Driver\OrmSchemaUpdater;

class MySqlDriver implements OrmDriver
{

    public readonly \PDO $pdo;

    /**
     *
     * <example>
     *
     *    $driver = new MySqlDriver("mysql:host=devdb;dbname=demo", "user", "test");
     *   $driver = new MySqlDriver("mysql:host=devdb;dbname=demo;user=user;password=test");
     * </example>
     *
     * @param \PDO|string $pdo
     * @param string|null $dbName
     * @param string|null $user
     * @param string|null $password
     * @param string|null $host
     * @param int|null $port
     */
    public function __construct(
        \PDO|string $pdo,
        private ?string $dbName = null,
        private ?string $user = null,
        private ?string $password = null,
        private ?string $host = null,
        private ?int $port = null
    ) {

        if (is_string($pdo)) {
            if ( ! str_starts_with($pdo, "mysql:"))
                throw new \InvalidArgumentException("Invalid DSN: '$pdo' - Must start with 'mysql:'.");
            $parsedDsn = $this->parseDSN($pdo);

            if ($this->dbName === null)
                $this->dbName = $parsedDsn["dbname"] ?? null;
            if ($this->user === null)
                $this->user = $parsedDsn["user"] ?? null;
            if ($this->password === null)
                $this->password = $parsedDsn["password"] ?? null;
            if ($this->host === null)
                $this->host = $parsedDsn["host"] ?? "localhost";
            if ($this->port === null)
                $this->port = $parsedDsn["port"] ?? 3306;

        }

    }

    public function connect() : \PDO
    {
        if (isset($this->pdo))
            return $this->pdo;
        $this->pdo = new \PDO("mysql:host={$this->host};port={$this->port};dbname={$this->dbName}", $this->user, $this->password);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $this->pdo;
    }


    private function parseDSN(string $dsn) : array
    {
        $ret = [];
        // strip mysql:
        $dsn = substr($dsn, 6);
        $parts = explode(";", $dsn);
        foreach ($parts as $part) {
            $part = explode("=", $part);
            $ret[$part[0]] = $part[1];
        }
        return $ret;
    }


    #[\Override] public function getSchemaUpdater(): OrmSchemaUpdater
    {
        return new MySqlSchemaUpdater($this->pdo, $this->dbName);
    }
}
