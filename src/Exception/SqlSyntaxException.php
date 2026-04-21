<?php

namespace Phore\MiniSql\Exception;

class SqlSyntaxException extends OrmQueryException
{
    public function __construct(string $message = "", public readonly string $sqlStmt = "", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
    
    public function getMessage() {
        return $this->message . " on sql stmt: " . $this->sqlStmt;
    }
    
}