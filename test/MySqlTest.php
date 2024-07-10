<?php

namespace Phore\MiniSql\Test;

use Phore\MiniSql\Orm;
use Phore\MiniSql\Test\mock\DemoEntity;

class MySqlTest extends \PHPUnit\Framework\TestCase
{

    private function getMSql() : Orm
    {
        $orm =  new Orm([DemoEntity::class], "mysql:host=devdb;dbname=demo;user=user;password=test");
        $orm->connect();
        return $orm;
    }


    public function testCreateSchema() : void
    {
        $m = $this->getMSql();
       // $m->getDriver()->getSchemaUpdater()->dropAllTables();
        print_r($m->updateSchema());
    }


    public function testCreate() : void
    {
        $m = $this->getMSql();
        $m->create(new DemoEntity(null,"John Doe2", "wurst@test23"));

        $e = $m->withClass(DemoEntity::class)->select([]);

        print_r ($e);
        $e = $e[0];

        $e->email = "new@new22";

        $m->update($e);


        $m->delete($e);
        $es = $m->withClass(DemoEntity::class)->select(["id" => 3]);
        foreach ($es as $e)
            $m->delete($e);


        echo "Created\n";
    }



}
