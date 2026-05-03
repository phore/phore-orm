<?php

namespace spec\Phore\MiniSql\Integration\mock;

use Phore\MiniSql\Schema\OrmClassSchema;
use Phore\MiniSql\Schema\OrmForeignKey;
use Phore\MiniSql\Schema\OrmIndex;

class HitTag
{

    public function __construct(
        public string $hit_id,
        /**
         * The Time offset in milliseconds from the Hit Date when this Tag was set
         * @var int
         */
        public int $timeOffsetMs,
        public string $tag,
        public string $value
    ) {
    }


    public static function __schema(): OrmClassSchema
    {
        return new OrmClassSchema(
            tableName: 'hit',
            primaryKey: ['hit_id', 'timeOffsetMs', 'tag'],
            autoincrement: false,
            columns: [
                'hit_id' => 'TEXT',
                'timeOffsetMs' => 'INTEGER',
                'tag' => 'TEXT',
                'value' => 'TEXT'
            ],
            indexes: [
                new OrmIndex(
                    columns: ['hit_id', "tag"],
                    indexName: 'idx_hit_id_tag',
                    type: 'INDEX'
                )
            ],
            foreignKeys: [
                new OrmForeignKey("hit_id", "hit", "id", onDelete: "cascade")
            ]
        );
    }
}
