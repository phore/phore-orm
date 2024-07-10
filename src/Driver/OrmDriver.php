<?php

namespace Phore\MiniSql\Driver;

interface OrmDriver
{


    public function connect() : \PDO;

    public function getSchemaUpdater() : OrmSchemaUpdater;
}
