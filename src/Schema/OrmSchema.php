<?php

namespace Phore\MiniSql\Schema;

class OrmSchema
{


    private $schemas = [];


    public function __construct(
        public array $classes = []
    ){
        foreach ($this->classes as $class) {
            if ( ! method_exists($class, "__schema")) {

                throw new \InvalidArgumentException("Class '$class' has no `public static function __schema() : OrmClassSchema` method: ");
            }
            $schema = $class::__schema();
            if ( ! $schema instanceof OrmClassSchema) {
                throw new \InvalidArgumentException("Class '$class' returned invalid schema object. Expected 'OrmClassSchema' got '" . get_class($schema) . "'");
            }

            $schema->autobuild($class);
            $this->schemas[$class] = $schema;

        }
    }


    public function addClassSchema(OrmClassSchema $schema)
    {
        $this->schemas[$schema->className] = $schema;
    }


    public function getSchema(string $class) : OrmClassSchema
    {
        if ( ! isset ($this->schemas[$class]))
            throw new \InvalidArgumentException("Class '$class' not found in schema.");
        return $this->schemas[$class];
    }

    /**
     * @return OrmClassSchema[]
     */
    public function getAllSchemas() : array
    {
        return array_values($this->schemas);
    }

    public function getClassSchemaByObject(object $obj) : OrmClassSchema
    {
        return $this->getSchema(get_class($obj));
    }




}
