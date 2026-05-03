<?php

namespace Phore\MiniSql\Driver\SQLite;

use Phore\MiniSql\Driver\OrmSchemaUpdater;
use Phore\MiniSql\Schema\OrmClassSchema;
use Phore\MiniSql\Schema\OrmForeignKey;
use Phore\MiniSql\Schema\OrmIndex;
use Phore\MiniSql\Schema\OrmSchema;

class SqliteSchemaUpdater implements OrmSchemaUpdater
{
    public string $lastSql = "";
    public array $log = [];

    public function __construct(private \PDO $pdo)
    {
    }

    #[\Override] public function updateSchema(OrmSchema $schema): array
    {
        foreach ($schema->getAllSchemas() as $classSchema) {
            $this->log[] = "Updating table for class $classSchema->className";
            $this->updateTable($classSchema);
        }

        return $this->log;
    }

    #[\Override] public function dropAllTables(): array
    {
        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $quotedTable = $this->quoteIdentifier($table);
            $sql = "DROP TABLE $quotedTable";
            $this->log[] = "Dropping table $table";
            $this->lastSql = $sql;
            $this->pdo->exec($sql);
        }
        return $this->log;
    }

    private function updateTable(OrmClassSchema $schema): void
    {
        if ($this->tableExists($schema->tableName)) {
            $this->log[] = "Table exists, altering";
            $this->alterTable($schema);
            return;
        }

        $this->log[] = "Table does not exist, creating";
        $this->createTable($schema);
    }

    private function tableExists(string $tableName): bool
    {
        $stmt = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table");
        $stmt->bindParam(':table', $tableName);
        $stmt->execute();
        return (bool)$stmt->fetch();
    }

    private function createTable(OrmClassSchema $schema): void
    {
        $tableName = $this->quoteIdentifier($schema->tableName);
        $parts = [];
        $parts[] = $this->generateColumnsSql($schema->columns, $schema->primaryKey, (bool)$schema->autoincrement);
        $parts[] = $this->generatePrimaryKeySql($schema->primaryKey, (bool)$schema->autoincrement);
        $parts[] = $this->generateForeignKeysSql($schema->foreignKeys);
        $parts = array_values(array_filter($parts, fn($v) => !empty($v)));

        $sql = "CREATE TABLE $tableName (" . implode(", ", $parts) . "\n);";
        $this->log[] = "Creating table with SQL: $sql";
        $this->lastSql = $sql;
        $this->pdo->exec($sql);

        foreach ($this->generateIndexesSql($schema->tableName, $schema->indexes) as $indexSql) {
            $this->log[] = "Creating index with SQL: $indexSql";
            $this->lastSql .= "\n" . $indexSql;
            $this->pdo->exec($indexSql);
        }
    }

    private function alterTable(OrmClassSchema $schema): void
    {
        $tableName = $this->quoteIdentifier($schema->tableName);
        $existingColumns = $this->getExistingColumns($schema->tableName);
        foreach ($schema->columns as $colName => $colType) {
            if ( ! isset($existingColumns[$colName])) {
                $quotedCol = $this->quoteIdentifier($colName);
                $sql = "ALTER TABLE $tableName ADD COLUMN $quotedCol $colType";
                $this->log[] = "Adding column with SQL: $sql";
                $this->lastSql .= "\n" . $sql;
                $this->pdo->exec($sql);
            }
        }

        if (count($existingColumns) > count($schema->columns)) {
            $this->log[] = "SQLite does not support dropping columns without table rebuild - skipping dropped columns";
        }

        $existingIndexes = $this->getExistingIndexes($schema->tableName);
        $desiredIndexes = $schema->indexes ?? [];
        foreach ($desiredIndexes as $index) {
            assert($index instanceof OrmIndex);
            if (isset($existingIndexes[$index->indexName])) {
                continue;
            }
            $columnsSql = implode(", ", array_map(fn(string $column) => $this->quoteIdentifier($column), $index->columns));
            $typeSql = strtoupper($index->type) === "UNIQUE" ? "UNIQUE " : "";
            $indexName = $this->quoteIdentifier($index->indexName);
            $sql = "CREATE {$typeSql}INDEX $indexName ON $tableName ($columnsSql)";
            $this->log[] = "Creating missing index with SQL: $sql";
            $this->lastSql .= "\n" . $sql;
            $this->pdo->exec($sql);
        }
    }

    private function getExistingColumns(string $tableName): array
    {
        $stmt = $this->pdo->query("PRAGMA table_info(" . $this->quoteIdentifier($tableName) . ")");
        $columns = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $ret = [];
        foreach ($columns as $column) {
            $ret[$column["name"]] = $column;
        }
        return $ret;
    }

    private function getExistingIndexes(string $tableName): array
    {
        $stmt = $this->pdo->query("PRAGMA index_list(" . $this->quoteIdentifier($tableName) . ")");
        $indexes = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $ret = [];
        foreach ($indexes as $index) {
            $ret[$index["name"]] = $index;
        }
        return $ret;
    }

    private function generateColumnsSql(array $columns, string|array|null $primaryKey, bool $autoIncrement): string
    {
        $columnsSql = [];
        foreach ($columns as $name => $type) {
            $quotedName = $this->quoteIdentifier($name);
            if ($autoIncrement && is_string($primaryKey) && $name === $primaryKey) {
                $columnsSql[] = "$quotedName INTEGER PRIMARY KEY AUTOINCREMENT";
                continue;
            }
            $columnsSql[] = "$quotedName $type";
        }
        return implode(", ", $columnsSql);
    }

    private function generatePrimaryKeySql(string|array|null $primaryKey, bool $autoIncrement): string
    {
        if ($autoIncrement) {
            return "";
        }
        if ($primaryKey === null) {
            return "";
        }
        if (is_string($primaryKey)) {
            $primaryKey = [$primaryKey];
        }
        $keysSql = implode(", ", array_map(fn(string $key) => $this->quoteIdentifier($key), $primaryKey));
        return "PRIMARY KEY ($keysSql)";
    }

    /**
     * @param OrmIndex[]|null $indexes
     * @return string[]
     */
    private function generateIndexesSql(string $tableName, ?array $indexes): array
    {
        if ($indexes === null) {
            return [];
        }

        $sql = [];
        foreach ($indexes as $index) {
            assert($index instanceof OrmIndex);
            $columnsSql = implode(", ", array_map(fn(string $column) => $this->quoteIdentifier($column), $index->columns));
            $typeSql = strtoupper($index->type) === "UNIQUE" ? "UNIQUE " : "";
            $sql[] = "CREATE {$typeSql}INDEX " . $this->quoteIdentifier($index->indexName) . " ON " . $this->quoteIdentifier($tableName) . " ($columnsSql)";
        }
        return $sql;
    }

    /**
     * @param OrmForeignKey[]|null $foreignKeys
     */
    private function generateForeignKeysSql(?array $foreignKeys): string
    {
        if ($foreignKeys === null) {
            return "";
        }
        $fks = [];
        foreach ($foreignKeys as $foreignKey) {
            $fks[] = "FOREIGN KEY (" . $this->quoteIdentifier($foreignKey->localColumn) . ") REFERENCES " . $this->quoteIdentifier($foreignKey->foreignTable) . "(" . $this->quoteIdentifier($foreignKey->foreignColumn) . ") ON DELETE {$foreignKey->onDelete} ON UPDATE {$foreignKey->onUpdate}";
        }
        return implode(", ", $fks);
    }

    private function quoteIdentifier(string $identifier): string
    {
        if ( ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier))
            throw new \InvalidArgumentException("Invalid SQL identifier: '$identifier'");
        return "`$identifier`";
    }
}
