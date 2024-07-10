<?php

namespace Phore\MiniSql\Schema;

class OrmForeignKey
{

    public function __construct(

        public string $localColumn,
        public string $foreignTable,
        public string $foreignColumn,
        public string $onDelete = "CASCADE",
        public string $onUpdate = "CASCADE"

    )
    {

    }

}
