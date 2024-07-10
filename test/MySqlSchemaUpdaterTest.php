<?php

namespace Phore\MiniSql\Test;

use Phore\MiniSql\Driver\MySQL\MySqlSchemaUpdater;
use Phore\MiniSql\Schema\OrmClassSchema;
use Phore\MiniSql\Schema\OrmIndex;
use Phore\MiniSql\Schema\OrmSchema;

class MySqlSchemaUpdaterTest extends \PHPUnit\Framework\TestCase
{

    private function getMSql() : \PDO
    {
        return new \PDO("mysql:host=devdb;dbname=demo", "user", "test");
    }

    public function testCreateTable()
    {
        $schema = new OrmSchema();
        $schema->addClassSchema(
            new OrmClassSchema(
                tableName: "demo_entity22",
                primaryKey: "id",
                autoincrement: true,
                columns: [
                    "id" => "int",
                    "name" => "varchar(255)",
                    "email222" => "varchar(255)"
                ],
                indexes: [
                    new OrmIndex(["id", "name"]),
                    new OrmIndex(["id"], "UNIQUE"),

                ]
            )
        );

        $updater = new MySqlSchemaUpdater($this->getMSql(), "demo");
        $updater->updateSchema($schema);


        print_r($updater->log);

    }


}
