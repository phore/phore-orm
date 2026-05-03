<?php

namespace spec\Phore\MiniSql\Integration;

use Phore\MiniSql\Orm;
use PhpSpec\ObjectBehavior;
use spec\Phore\MiniSql\Integration\mock\Hit;
use spec\Phore\MiniSql\Integration\mock\HitTag;

class OrmDemoForeignKeySpec extends ObjectBehavior
{
    private function createOrm(): Orm
    {
        $orm = new Orm([Hit::class, HitTag::class], "sqlite::memory:");
        $orm->connect();
        $orm->updateSchema();
        return $orm;
    }

    function it_creates_entities(): void
    {
        $orm = $this->createOrm();
        $hit = new Hit("abcd", "2024-01-01 12:00:00", "", "", "");
        $orm->create($hit);




        $hitTag = new HitTag("abcd", 1000, "tag1", "value1");
        $orm->create($hitTag);
        $data = $orm->read("abcd", Hit::class);
    }

}
