<?php

namespace Phore\MiniSql;

class SQLAlterTableHandler {
    private \PDO $pdo;
    private string $database;
    private string $table;
    private bool $isSQLite;

    public function __construct(\PDO $pdo, string $database, string $table) {
        $this->pdo = $pdo;
        $this->database = $database;
        $this->table = $table;
        $this->isSQLite = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }

    public function updateSchema(array $columns): void {
        if ($this->tableExists()) {
            $alterSql = $this->generateAlterTableStatement($columns);
            echo $alterSql;
            if ($alterSql) {
                $this->pdo->exec($alterSql);
            }
        } else {
            $createSql = $this->generateCreateTableStatement($columns);
            $this->pdo->exec($createSql);
        }
    }

    private function tableExists(): bool {
        if ($this->isSQLite) {
            $stmt = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
            $stmt->bindParam(1, $this->table);
        } else {
            $stmt = $this->pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :database AND TABLE_NAME = :table");
            $stmt->bindParam(':database', $this->database);
            $stmt->bindParam(':table', $this->table);
        }
        $stmt->execute();
        return (bool) $stmt->fetch();
    }

    private function generateCreateTableStatement(array $columns): string {
        $columnDefinitions = [];
        foreach ($columns as $colName => $colType) {
            $columnDefinitions[] = "`$colName` $colType";
        }
        $columnsSql = implode(', ', $columnDefinitions);
        return "CREATE TABLE `{$this->table}` ($columnsSql);";
    }

    private function generateAlterTableStatement(array $columns): string {
        $existingColumns = $this->getExistingColumns();
        $diff = $this->getColumnDifferences($existingColumns, $columns);
        return $this->buildAlterTableStatement($diff);
    }

    private function getExistingColumns(): array {
        if ($this->isSQLite) {
            $stmt = $this->pdo->prepare("PRAGMA table_info({$this->table})");
        } else {
            $stmt = $this->pdo->prepare("SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_KEY FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :database AND TABLE_NAME = :table");
            $stmt->bindParam(':database', $this->database);
            $stmt->bindParam(':table', $this->table);
        }
        $stmt->execute();

        if ($this->isSQLite) {
            $columns = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $existingColumns = [];
            foreach ($columns as $column) {
                $existingColumns[$column['name']] = [
                    'COLUMN_NAME' => $column['name'],
                    'COLUMN_TYPE' => $column['type'],
                    'COLUMN_KEY' => $column['pk'] ? 'PRI' : ''
                ];
            }
            return $existingColumns;
        } else {
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }
    }

    private function getColumnDifferences(array $existing, array $new): array {
        $diff = ['add' => [], 'modify' => [], 'drop' => []];

        foreach ($new as $colName => $colType) {
            if (isset($existing[$colName])) {
                if ($existing[$colName]['COLUMN_TYPE'] !== $colType) {
                    $diff['modify'][$colName] = $colType;
                }
            } else {
                $diff['add'][$colName] = $colType;
            }
        }

        foreach ($existing as $colName => $colDetails) {
            if (!isset($new[$colName])) {
                $diff['drop'][] = $colName;
            }
        }

        return $diff;
    }

    private function buildAlterTableStatement(array $diff): string {
        $sql = "ALTER TABLE `{$this->table}`";
        $actions = [];

        foreach ($diff['add'] as $colName => $colType) {
            $actions[] = "ADD COLUMN `$colName` $colType";
        }

        foreach ($diff['modify'] as $colName => $colType) {
            //$actions[] = "MODIFY COLUMN `$colName` $colType";
        }

        foreach ($diff['drop'] as $colName) {
           // $actions[] = "DROP COLUMN `$colName`";
        }

        if (count ($actions) === 0)
            return "";
        return $sql . ' ' . implode(', ', $actions) . ';';
    }
}
