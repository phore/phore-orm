<?php

namespace Phore\MiniSql\Driver;

use Phore\MiniSql\Driver\MySQL\MySqlDriver;

class OrmDriverFactory
{

    public static function GetDriver(string $dsn) : OrmDriver
    {
        if (str_starts_with($dsn, "mysql:"))
            return new MySqlDriver($dsn);

        $dsn = explode(":", $dsn, 2)[0];
        throw new \InvalidArgumentException("Unknown DSN-scheme: '" . $dsn . "'. Supported: mysql");
    }

}
