<?php

use Phore\MiniSql\Orm;
use Phore\MiniSql\Test\mock\DemoEntity;

function sqliteOrm(): Orm
{
    $orm = new Orm([DemoEntity::class], "sqlite::memory:");
    $orm->connect();
    $orm->updateSchema();
    return $orm;
}

it("creates and lists entities", function () {
    $orm = sqliteOrm();
    $entity = new DemoEntity(1, "John Doe", "john@example.com");

    $orm->create($entity);

    $all = $orm->withClass(DemoEntity::class)->listAll();
    expect($all)->toHaveCount(1);
    expect($all[0]->name)->toBe("John Doe");
});

it("selects, updates and deletes entities", function () {
    $orm = sqliteOrm();
    $entity = new DemoEntity(2, "Jane Doe", "jane@example.com");
    $orm->create($entity);

    $selected = $orm->withClass(DemoEntity::class)->select(["id" => 2, "name" => "Jane Doe"]);
    expect($selected)->toHaveCount(1);

    $selectedEntity = $selected[0];
    $selectedEntity->email = "jane@new.example";
    expect($orm->update($selectedEntity))->toBeTrue();

    $updated = $orm->withClass(DemoEntity::class)->selectOne(["id" => 2, "name" => "Jane Doe"]);
    expect($updated)->not->toBeNull();
    expect($updated->email)->toBe("jane@new.example");

    expect($orm->delete($selectedEntity))->toBeTrue();
    expect($orm->withClass(DemoEntity::class)->listAll())->toHaveCount(0);
});
