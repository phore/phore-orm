<?php

namespace Phore\MiniSql\Driver;

use Phore\MiniSql\Schema\OrmSchema;

interface OrmSchemaUpdater
{

    public function updateSchema(OrmSchema $schema): array;

    public function dropAllTables(): array;

}
