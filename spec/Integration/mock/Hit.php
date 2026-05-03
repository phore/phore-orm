<?php

namespace spec\Phore\MiniSql\Integration\mock;

use Phore\MiniSql\Schema\OrmClassSchema;
use Phore\MiniSql\Schema\OrmIndex;

class Hit
{

    public function __construct(
        public string $id,
        public string $date,
        public string $ip,
        public string $host,
        public string $uri
    ) {

    }

    public static function __schema(): OrmClassSchema
    {
        return new OrmClassSchema(
            tableName: 'hit',
            primaryKey: 'id',
            autoincrement: false,
            columns: [
                'id' => 'TEXT',
                'date' => 'datetime',
                'ip' => 'TEXT',
                'host' => 'TEXT',
                'uri' => 'TEXT'
            ],
            indexes: [
                new OrmIndex(
                    columns: ['date'],
                    indexName: 'idx_date',
                    type: 'INDEX'
                ),
                new OrmIndex(
                    columns: ['host'],
                    indexName: 'idx_host',
                    type: 'INDEX'
                )
            ]
        );
    }


}
