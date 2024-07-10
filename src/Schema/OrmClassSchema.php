<?php

namespace Phore\MiniSql\Schema;

class OrmClassSchema
{

    public function __construct(
        public ?string $tableName = null,

        /**
         * Provide on of the following:
         * - string: The name of the primary key column
         * - array: An array of column names that are part of the primary key
         * - null: No primary key
         *
         * @var string|array|null
         */
        public string|array|null $primaryKey = null,

        /**
         * Set to true if the primary key is autoincrement
         *
         * This is only valid if the primary key is a single column and of type int
         *
         * @var bool|null
         */
        public ?bool $autoincrement = false,

        /**
         * Provide a map of column names to column types
         *
         * <example>
         *     [
         *        "id" => "int",
         *       "name" => "varchar(255)"
         *    ]
         * </example>
         *
         * @var array|null
         */
        public ?array $columns = null,



        /**
         * Provide a map of index names to column names
         *
         * <example>
         *     [
         *       "index_name" => ["column1", "column2"]
         *   ]
         * </example>
         * @var array|null
         */
        public ?array $indexes = null,

        /**
         * <example>
         *     [
         *      new OrmForeignKey("local_column", "foreign_table", "foreign_column")
         * ]
         * </example>
         * @var OrmForeignKey[]
         */
        public ?array $foreignKeys = null
    ){}


    /**
     * Will be assigned by __autobild()
     * @var class-string
     */
    public $className = null;

    public function autobuild($className) {
        $this->className = $className;
        $reflectionClass = new \ReflectionClass($className);

        if ($this->tableName === null) {
            $this->tableName = $reflectionClass->getShortName();
        }

        if ($this->columns === null) {
            $this->columns = [];
            foreach ($reflectionClass->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->name === "id") {
                    $this->columns[$property->name] = "int";
                    continue;
                }

                $this->columns[$property->name] = "varchar(255)";
            }
        }

        if ($this->primaryKey === null) {
            if (isset($this->columns["id"])) {
                $this->primaryKey = "id";
            }
        }


    }

    public function getColumnNames() : array {
        return array_keys($this->columns);
    }

    public function getColumnValuesFromObject(object $obj) : array {
        $values = [];
        foreach ($this->columns as $columnName => $columnType) {
            if ( ! property_exists($obj, $columnName))
                throw new \InvalidArgumentException("Property '$columnName' not found in object of class '" . get_class($obj) . "'");
            $values[] = $obj->$columnName;
        }
        return $values;
    }

    public function createInstanceWithoutConstructor() : object {
        $reflection = new \ReflectionClass($this->className);
        return $reflection->newInstanceWithoutConstructor();
    }

}
