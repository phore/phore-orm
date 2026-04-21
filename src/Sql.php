<?php

namespace Phore\MiniSql;

class Sql
{
    public function __construct(public readonly string $sql, public readonly array $params = [])
    {
        // Count all ? and compare with params count
        $count = substr_count($sql, "?");
        if ($count !== count($this->params)) {
            throw new \InvalidArgumentException("Number of parameters (" . count($this->params) . ") does not match number of placeholders (" . $count .") in SQL. ");
        }
    }
    
    public function __getSelect(Orm $orm) : string
    {
        // replace ? by param values
        $params = $this->params;
        $sql = $this->sql;
        $sql = preg_replace_callback("/\?/", function ($match) use ($orm, &$params) {
            $param = array_shift($params);
            return $orm->getPdo()->quote($param, \PDO::PARAM_STR);
        }, $sql);
        return $sql;
    }
}