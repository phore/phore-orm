<?php

namespace Phore\MiniSql\Test;

use Phore\MiniSql\Orm;
use Phore\MiniSql\Test\mock\DemoEntity;


class BasicTest extends \PHPUnit\Framework\TestCase
{



    public function testCreateSchema()
    {
        $m = new Orm("sqlite:/tmp/database.db", DemoEntity::class);
        $m->updateSchema();
    }


    public function testCreate()
    {

        $m = new Orm("sqlite:/tmp/database.db", DemoEntity::class);

       // $m->create(new DemoEntity(3, "John Doe2", "wurst@test23"));
        echo "Created\n";

    }

    public function testList() {
        $m = new Orm("sqlite:/tmp/database.db", DemoEntity::class);

        print_r($m->listAll());
    }

    public function testFind()
    {
        $m = new Orm("sqlite:/tmp/database.db", DemoEntity::class);
        $data = $m->select(["id" => "2"]);
        print_r($data);
    }

    public function testUpdate() {
        $m = new Orm("sqlite:/tmp/database.db", DemoEntity::class);
        $data = $m->select(["id" => "2"]);

        $data->email = "new@email";
        $m->update($data);
    }

}
