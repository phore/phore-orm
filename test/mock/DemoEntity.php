<?php

namespace Phore\MiniSql\Test\mock;

use Phore\MiniSql\Schema\OrmClassSchema;
use Phore\MiniSql\Schema\OrmIndex;

class DemoEntity {
    public static function __schema() {
        return new OrmClassSchema(
            tableName: "demo_entity",
            primaryKey: "id",
            autoincrement: false,
            columns: [
                "id" => "int",
                "name" => "varchar(255)",
                "email" => "varchar(255)"
            ],
            indexes: [
                new OrmIndex(["name", "email"])
            ]
            );
    }

    public function __construct(
        public ?int $id,
        public string $name,
        public string $email
    ) {}


}
