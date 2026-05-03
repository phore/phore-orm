<?php

namespace spec\Phore\MiniSql\Integration;

use Phore\MiniSql\Orm;
use Phore\MiniSql\Test\mock\DemoEntity;
use PhpSpec\ObjectBehavior;

class OrmSqliteSpec extends ObjectBehavior
{
    private function createOrm(): Orm
    {
        $orm = new Orm([DemoEntity::class], "sqlite::memory:");
        $orm->connect();
        $orm->updateSchema();
        return $orm;
    }

    function it_creates_and_lists_entities(): void
    {
        $orm = $this->createOrm();
        $entity = new DemoEntity(1, "John Doe", "john@example.com");
        $orm->create($entity);

        $all = $orm->withClass(DemoEntity::class)->listAll();
        if (count($all) !== 1) {
            throw new \RuntimeException("Expected one row in SQLite table.");
        }
        if ($all[0]->name !== "John Doe") {
            throw new \RuntimeException("Unexpected entity name.");
        }
    }

    function it_selects_entities_by_criteria(): void
    {
        $orm = $this->createOrm();
        $entity = new DemoEntity(2, "Jane Doe", "jane@example.com");
        $orm->create($entity);

        $selected = $orm->withClass(DemoEntity::class)->select(["id" => 2, "name" => "Jane Doe"]);
        if (count($selected) !== 1) {
            throw new \RuntimeException("Expected to select exactly one row.");
        }
    }

    function it_updates_entity_fields(): void
    {
        $orm = $this->createOrm();
        $entity = new DemoEntity(2, "Jane Doe", "jane@example.com");
        $orm->create($entity);

        $selected = $orm->withClass(DemoEntity::class)->select(["id" => 2, "name" => "Jane Doe"]);
        if (count($selected) !== 1) {
            throw new \RuntimeException("Expected to select exactly one row.");
        }

        $selectedEntity = $selected[0];
        $selectedEntity->email = "jane@new.example";
        if ($orm->update($selectedEntity) !== true) {
            throw new \RuntimeException("Expected update() to return true.");
        }

        $updated = $orm->withClass(DemoEntity::class)->selectOne(["id" => 2, "name" => "Jane Doe"]);
        if ($updated === null || $updated->email !== "jane@new.example") {
            throw new \RuntimeException("Expected updated row to be returned.");
        }
    }

    function it_deletes_entities(): void
    {
        $orm = $this->createOrm();
        $entity = new DemoEntity(2, "Jane Doe", "jane@example.com");
        $orm->create($entity);

        $selected = $orm->withClass(DemoEntity::class)->select(["id" => 2, "name" => "Jane Doe"]);
        if (count($selected) !== 1) {
            throw new \RuntimeException("Expected to select exactly one row.");
        }

        $selectedEntity = $selected[0];
        if ($orm->delete($selectedEntity) !== true) {
            throw new \RuntimeException("Expected delete() to return true.");
        }
        if (count($orm->withClass(DemoEntity::class)->listAll()) !== 0) {
            throw new \RuntimeException("Expected empty table after delete.");
        }
    }
}
