<?php

namespace Phore\MiniSql\Schema;

class OrmIndex
{

    public array $columns;

    public function __construct(

        /**
         * The columns to be indexed
         *
         * @var string[]|string
         */
        string|array $columns,

        /**
         * If not set, the index name will be generated from the column names
         *
         * @var string|null
         */
        public ?string $indexName = null,

        /**
         *
         * Valid values are:
         * - INDEX
         * - UNIQUE
         * - FULLTEXT
         * - SPATIAL
         *
         *
         * @var string
         */
        public string $type = "INDEX",
    ){
        if (is_string($columns))
            $columns = [$columns];
        foreach ($columns as $col) {
            if ( ! is_string($col))
                throw new \InvalidArgumentException("Invalid column name. Expected string, got '" . gettype($col) . "'");
        }
        $this->columns = $columns;
        if ($this->indexName === null)
            $this->indexName = "idx_" . implode("_", $this->columns);
    }

}
