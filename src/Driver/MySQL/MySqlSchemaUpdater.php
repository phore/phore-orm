<?php
namespace Phore\MiniSql\Driver\MySQL;

use Phore\MiniSql\Driver\OrmSchemaUpdater;
use Phore\MiniSql\Schema\OrmSchema;
use Phore\MiniSql\Schema\OrmClassSchema;
use Phore\MiniSql\Schema\OrmForeignKey;
use Phore\MiniSql\Schema\OrmIndex;

class MySqlSchemaUpdater implements OrmSchemaUpdater
{
    private \PDO $pdo;
    private string $database;
    public string $lastSql = "";
    public array $log = [];

    public function __construct(\PDO $pdo, string $database)
    {
        $this->pdo = $pdo;
        $this->database = $database;
    }

    public function updateSchema(OrmSchema $schema): array
    {
        try {
            foreach ($schema->getAllSchemas() as $schema) {
                $this->log[] = "Updating table for class $schema->className";
                $this->updateTable($schema);
            }
        } catch (\Exception $e) {
            throw new \Exception("Error updating schema: " . $e->getMessage() . "\nLog: ". implode("\n", $this->log), 0, $e);
        }
        return $this->log;
    }

    public function dropAllTables(): array
    {
        $stmt = $this->pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :database");
        $stmt->bindParam(':database', $this->database);
        $stmt->execute();
        $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $this->log[] = "Dropping table $table";
            $this->pdo->exec("DROP TABLE $table");
        }
        return $this->log;
    }

    private function updateTable(OrmClassSchema $schema): void
    {
        if ($this->tableExists($schema->tableName)) {
            $this->log[] = "Table exists, altering";
            $this->alterTable($schema);
        } else {
            $this->log[] = "Table does not exist, creating";
            $this->createTable($schema);
        }

    }

    private function tableExists(string $tableName): bool
    {
        $stmt = $this->pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :database AND TABLE_NAME = :table");
        $stmt->bindParam(':database', $this->database);
        $stmt->bindParam(':table', $tableName);
        $stmt->execute();
        return (bool) $stmt->fetch();
    }

    private function createTable(OrmClassSchema $schema): void
    {
        $arr = [];
        $arr[] = $this->generateColumnsSql($schema->columns, $schema->primaryKey, $schema->autoincrement);
        $arr[] = $this->generatePrimaryKeySql($schema->primaryKey);
        $arr[] = $this->generateIndexesSql($schema->indexes);
        $arr[] = $this->generateForeignKeysSql($schema->foreignKeys);
        $arr = array_values(array_filter($arr, fn($v) => !empty($v)));
        $sql = "CREATE TABLE `{$schema->tableName}` (" . implode(", ", $arr) . "\n);";
        $this->log[] = "Creating table with SQL: $sql";
        $this->lastSql = $sql;
        $this->pdo->exec($sql);
    }

    private function alterTable(OrmClassSchema $schema): void
    {
        $existingColumns = $this->getExistingColumns($schema->tableName);
        $columnDiff = $this->getColumnDifferences($existingColumns, $schema->columns);
        $existingIndexes = $this->getExistingIndexes($schema->tableName);
        $indexDiff = $this->getIndexDifferences($existingIndexes, $schema->indexes);
        $existingForeignKeys = $this->getExistingForeignKeys($schema->tableName);
        $foreignKeyDiff = $this->getForeignKeyDifferences($existingForeignKeys, $schema->foreignKeys);
        $alterSql = $this->generateAlterTableSql($schema->tableName, $columnDiff, $indexDiff, $foreignKeyDiff);
        if (!empty($alterSql)) {
            $this->log[] = "Altering table with SQL: $alterSql";
            $this->lastSql .= $alterSql;
            $this->pdo->exec($alterSql);
        } else {
            $this->log[] = "No changes to table";
        }
    }

    private function getExistingColumns(string $tableName): array
    {
        $stmt = $this->pdo->prepare("SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_KEY, EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :database AND TABLE_NAME = :table");
        $stmt->bindParam(':database', $this->database);
        $stmt->bindParam(':table', $tableName);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function getExistingIndexes(string $tableName): array
    {
        $stmt = $this->pdo->prepare("SHOW INDEXES FROM $tableName");
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function getExistingForeignKeys(string $tableName): array
    {
        $stmt = $this->pdo->prepare("SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = :database AND TABLE_NAME = :table AND REFERENCED_TABLE_NAME IS NOT NULL");
        $stmt->bindParam(':database', $this->database);
        $stmt->bindParam(':table', $tableName);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function getColumnDifferences(array $existingColumns, array $newColumns): array
    {
        $diff = ['add' => [], 'modify' => [], 'drop' => []];
        foreach ($newColumns as $colName => $colType) {
            $exists = false;
            foreach ($existingColumns as $existingColumn) {
                if ($existingColumn['COLUMN_NAME'] === $colName) {
                    $exists = true;
                    if ($existingColumn['COLUMN_TYPE'] !== $colType) {
                        $diff['modify'][$colName] = $colType;
                        $this->log[] = "Column $colName is different: Current type: {$existingColumn['COLUMN_TYPE']}, New type: $colType";
                    }
                    break;
                }
            }
            if (!$exists) {
                $diff['add'][$colName] = $colType;
            }
        }
        foreach ($existingColumns as $existingColumn) {
            if (!isset($newColumns[$existingColumn['COLUMN_NAME']])) {
                $diff['drop'][] = $existingColumn['COLUMN_NAME'];
            }
        }
        return $diff;
    }

    /**
     * @param array $existingIndexes
     * @param OrmIndex[]|null $newIndexes
     * @return array|array[]
     */
    private function getIndexDifferences(array $existingIndexes, ?array $newIndexes): array
    {
        $diff = ['add' => [], 'drop' => []];
        $existingIndexNames = array_column($existingIndexes, 'Key_name');
        if ($newIndexes === null) {
            $newIndexes = [];
        }
        $newIndexNames = $newIndexes ? array_map(fn($index) => $index->indexName, $newIndexes) : [];
        foreach ($newIndexes as $index) {
            if (!in_array($index->indexName, $existingIndexNames)) {
                $diff['add'][] = $index;
            }
        }
        foreach ($existingIndexes as $existingIndex) {
            if (!in_array($existingIndex['Key_name'], $newIndexNames)) {
                $diff['drop'][] = $existingIndex['Key_name'];
            }
        }
        return $diff;
    }

    private function getForeignKeyDifferences(array $existingForeignKeys, ?array $newForeignKeys): array
    {
        if (is_null($newForeignKeys)) {
            $newForeignKeys = [];
        }
        $diff = ['add' => [], 'drop' => []];
        $existingForeignKeyNames = array_column($existingForeignKeys, 'CONSTRAINT_NAME');
        $newForeignKeyNames = $newForeignKeys ? array_map(fn($fk) => $fk->localColumn, $newForeignKeys) : [];
        foreach ($newForeignKeys as $foreignKey) {
            if (!in_array($foreignKey->localColumn, $existingForeignKeyNames)) {
                $diff['add'][] = $foreignKey;
            }
        }
        foreach ($existingForeignKeys as $existingForeignKey) {
            if (!in_array($existingForeignKey['CONSTRAINT_NAME'], $newForeignKeyNames)) {
                $diff['drop'][] = $existingForeignKey['CONSTRAINT_NAME'];
            }
        }
        return $diff;
    }

    private function generateAlterTableSql(string $tableName, array $columnDiff, array $indexDiff, array $foreignKeyDiff): string
    {
        $actions = [];
        foreach ($columnDiff['add'] as $colName => $colType) {

            $actions[] = "ADD COLUMN `$colName` $colType";
        }
        foreach ($columnDiff['modify'] as $colName => $colType) {
            $actions[] = "MODIFY COLUMN `$colName` $colType";
        }
        foreach ($columnDiff['drop'] as $colName) {
            $actions[] = "DROP COLUMN `$colName`";
        }
        foreach ($indexDiff['add'] as $index) {
            assert( $index instanceof OrmIndex);
            $columnsSql = implode(", ", $index->columns);
            $actions[] = "ADD INDEX `{$index->indexName}` ($columnsSql)";
        }
        foreach ($indexDiff['drop'] as $indexName) {
            $actions[] = "DROP INDEX `$indexName`";
        }
        foreach ($foreignKeyDiff['add'] as $foreignKey) {
            $actions[] = "ADD FOREIGN KEY (`{$foreignKey->localColumn}`) REFERENCES `{$foreignKey->foreignTable}`(`{$foreignKey->foreignColumn}`) ON DELETE {$foreignKey->onDelete} ON UPDATE {$foreignKey->onUpdate}";
        }
        foreach ($foreignKeyDiff['drop'] as $foreignKeyName) {
            $actions[] = "DROP FOREIGN KEY `$foreignKeyName`";
        }
        if (empty($actions)) {
            return '';
        }
        return "ALTER TABLE `$tableName` " . implode(", ", $actions) . ";";
    }

    private function generateColumnsSql(array $columns, array|string|null $primaryKey, bool $autoIncrement): string
    {
        $columnsSql = [];
        foreach ($columns as $name => $type) {
            if ($name === $primaryKey && $autoIncrement) {
                $columnsSql[] = "`$name` $type AUTO_INCREMENT";
            } else {
                $columnsSql[] = "`$name` $type";
            }
        }
        return implode(", ", $columnsSql);
    }

    private function generatePrimaryKeySql(string|array|null $primaryKey): string
    {
        if (is_null($primaryKey)) {
            return '';
        }
        if (is_array($primaryKey)) {
            $primaryKey = implode(", ", $primaryKey);
        }

        return "PRIMARY KEY (`$primaryKey`)";
    }

    /**
     * @param OrmIndex[]|null $indexes
     * @return string
     */
    private function generateIndexesSql(?array $indexes): string
    {
        if (is_null($indexes)) {
            return '';
        }
        $indexesSql = [];
        foreach ($indexes as $index) {
            $columnsSql = implode(", ", $index->columns);
            $indexesSql[] = "INDEX `{$index->indexName}` ($columnsSql)";
        }
        return implode(", ", $indexesSql);
    }

    private function generateForeignKeysSql(?array $foreignKeys): string
    {
        if (is_null($foreignKeys)) {
            return '';
        }
        $foreignKeysSql = [];
        foreach ($foreignKeys as $foreignKey) {
            $foreignKeysSql[] = "FOREIGN KEY (`{$foreignKey->localColumn}`) REFERENCES `{$foreignKey->foreignTable}`(`{$foreignKey->foreignColumn}`) ON DELETE {$foreignKey->onDelete} ON UPDATE {$foreignKey->onUpdate}";
        }
        return implode(", ", $foreignKeysSql);
    }
}
