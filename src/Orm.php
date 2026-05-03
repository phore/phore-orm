<?php

namespace Phore\MiniSql;

use Phore\MiniSql\Driver\MySQL\MySqlSchemaUpdater;
use Phore\MiniSql\Driver\OrmDriver;
use Phore\MiniSql\Driver\OrmDriverFactory;
use Phore\MiniSql\Exception\SqlSyntaxException;
use Phore\MiniSql\Schema\OrmClassSchema;
use Phore\MiniSql\Schema\OrmSchema;

/**
 * @template T
 */
class Orm
{
    private \PDO $pdo;
    private OrmDriver $driver;

    public OrmSchema $schema;


    private static \WeakMap $origData;


    private ?string $withClass = null;

    /**
     *
     */
    public function __construct(public readonly array $classNames = [],  OrmDriver|string $driver = null) {
        if ($driver !== null) {
            if (is_string($driver)) {
                $driver = OrmDriverFactory::GetDriver($driver);
            }
            $this->driver = $driver;
        }
        if (! isset(self::$origData)) {
            self::$origData = new \WeakMap();
        }

    }




    public function setDriver(OrmDriver|string $driver) {
        if (is_string($driver)) {
            $driver = OrmDriverFactory::GetDriver($driver);
        }
        $this->driver = $driver;
    }

    public function getDriver() : OrmDriver {
        return $this->driver;
    }


    public function connect(): void {
        if ($this->driver === null) {
            throw new \InvalidArgumentException("No driver set");
        }
        $this->pdo = $this->driver->connect();
        $this->schema = new OrmSchema($this->classNames);
    }

    private function throwExceptionIfNotConnected() {
        if (! isset($this->pdo)) {
            throw new \RuntimeException("Not connected to database. Call connect() first.");
        }
    }


    public function getPdo() : \PDO {
        return $this->pdo;
    }


    public function updateSchema(): array {
        return $this->driver->getSchemaUpdater()->updateSchema($this->schema);
    }

    public function query(string $stmt, array $params = []): \PDOStatement {
        $this->throwExceptionIfNotConnected();

        $query = $this->pdo->prepare($stmt);
        try {
            $query->execute($params);
        } catch (\Exception $e) {
            throw new SqlSyntaxException($e->getMessage(), $stmt, $e->getCode(), $e);
        }
        return $query;
    }

    public function create(object $obj): bool {
        $this->throwExceptionIfNotConnected();
        $schema = $this->schema->getClassSchemaByObject($obj);


        $columns = implode(", ", array_map(fn($key) => "`" . $key . "`", $schema->getColumnNames()));
        $placeholders = implode(", ", array_fill(0, count($schema->getColumnNames()), '?'));
        $stmt = "INSERT INTO `{$schema->tableName}` ($columns) VALUES ($placeholders)";

        $result = $this->query($stmt, array_values($schema->getColumnValuesFromObject($obj)));

        $lastInsertId = $this->pdo->lastInsertId();
        if ($schema->autoincrement && $lastInsertId) {
            if (! is_string($schema->primaryKey))
                throw new \InvalidArgumentException("Primary key definition must be a single column for autoincrement in class: {$schema->className}");
            $obj->{$schema->primaryKey} = $lastInsertId;
        }

        if ($result->rowCount() === 0)
            throw new \InvalidArgumentException("Failed to create object. Error: " . $this->pdo->errorInfo()[2]);
        return true;
    }

    /**
     * @param int $id
     * @return null|T
     */
    public function read(int $id, string $className = null): ?object {
        $this->throwExceptionIfNotConnected();

        if ($className === null) {
            $className = $this->withClass ?? throw new \InvalidArgumentException("No class specified for read operation");
        }
        $schema = $this->schema->getSchema($className);

        $stmt = "SELECT * FROM {$schema->className} WHERE `{$schema->primaryKey}` = ?";
        $data = $this->query($stmt, [$id])->fetch(\PDO::FETCH_ASSOC);


        if ($data) {
            return $this->arrayToObject($className, $data);
        }
        return null;
    }

    public function update(object $obj): bool {
        $this->throwExceptionIfNotConnected();

        $changedColumnValues = $this->changedColumns($obj);
        if (empty($changedColumnValues))
            return false; // Noting to do

        $schema = $this->schema->getClassSchemaByObject($obj);
        $properties = array_values($changedColumnValues);

        $stmt = "UPDATE {$schema->tableName} SET " . implode(", ", array_map(fn($key) => "`$key` = ?", array_keys($changedColumnValues)));

        $stmt = $this->addPrimaryKeyToWhereStmt($stmt, $schema, $obj, $properties);

        if ($this->query($stmt, $properties)->rowCount() === 0)
            throw new \InvalidArgumentException("Failed to update object. Error: " . $this->pdo->errorInfo()[2]);
        $this->resetOrigData($obj);
        return true;
    }

    private function addPrimaryKeyToWhereStmt(string $stmt, OrmClassSchema $schema, object $obj, array &$preparedValues): string {
        if ($schema->primaryKey === null) {
            $pkKeys = $schema->getColumnNames();
        } else {
            $pkKeys = $schema->primaryKey;
            if (is_string($pkKeys)) {
                $pkKeys = [$pkKeys];
            }
        }

        $stmt .= " WHERE " . implode(" AND ", array_map(fn($key) => "`$key` = ?", $pkKeys));
        // Append to prepared values
        $preparedValues = array_merge($preparedValues, array_map(fn($key) => $this->getOriginalColumnValue($obj, $key), $pkKeys));
        return $stmt;
    }

    public function delete(object $obj): bool {
        $this->throwExceptionIfNotConnected();

        $schema = $this->schema->getClassSchemaByObject($obj);
        $properties = [];
        $stmt = "DELETE FROM `{$schema->tableName}` ";
        $stmt = $this->addPrimaryKeyToWhereStmt($stmt, $schema, $obj, $properties);
        return $this->query($stmt, $properties)->rowCount() > 0;
    }

    /**
     * Return a new Instance with preselected class
     *
     * @param class-string<T> $className
     * @return Orm<T>
     */
    public function withClass(string $className): Orm {
        $orm = new Orm($this->classNames);
        $orm->pdo = $this->pdo;
        $orm->driver = $this->driver;
        $orm->schema = $this->schema;

        $orm->withClass = $className;
        return $orm;
    }

    /**
     * @param class-string<T> $className
     * @return array<T>
     */
    public function listAll(string $className = null): array {
        $this->throwExceptionIfNotConnected();

        if ($className === null) {
            $className = $this->withClass ?? throw new \InvalidArgumentException("No class specified for listAll operation");
        }
        $schema = $this->schema->getSchema($className);
        $stmt = "SELECT * FROM {$schema->tableName}";
        $results = $this->query($stmt)->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn($result) => $this->arrayToObject($className, $result), $results);
    }



    /**
     * @param array<string, mixed> $conditions
     * @param array<string> $orderBy  An array of column names => ASC or DESC
     * @param class-string<T> $className
     * @return array<T>
     */
    public function select(array $conditions, array $orderBy = [], string $className = null): array {
        $this->throwExceptionIfNotConnected();

        if ($className === null) {
            $className = $this->withClass ?? throw new \InvalidArgumentException("No class specified for select operation");
        }
        $schema = $this->schema->getSchema($className);
        $stmt = "SELECT * FROM {$schema->tableName}";

        $clauses = [];
        foreach ($conditions as $key => $value) {
            if (is_numeric($key) && $value instanceof Sql) {
                $clauses[] = "(" . $value->__getSelect($this) . ")";
                continue;
            }
            if (! in_array($key, $schema->getColumnNames())) {
                throw new \InvalidArgumentException("Invalid column name in conditions: $key");
            }
            $clauses[] = "`$key` = " . $this->pdo->quote($value);
        }

        $clauses = implode(" AND ", $clauses);
        if ($clauses !== "")
            $stmt .= " WHERE $clauses";

        if (!empty($orderBy)) {
            // Validate keys and order direction
            $validKeys = $schema->getColumnNames();
            $validOrder = ['ASC', 'DESC'];
            foreach ($orderBy as $key => $value) {
                if (!in_array($key, $validKeys)) {
                    throw new \InvalidArgumentException("Invalid column name in order by clause: $key");
                }
                if (!in_array($value, $validOrder)) {
                    throw new \InvalidArgumentException("Invalid order direction in order by clause: $value");
                }
            }
            $stmt .= " ORDER BY " . implode(", ", array_map(fn($key, $value) => "$key $value", array_keys($orderBy), $orderBy));
        }

        try {

            $results = $this->query($stmt, [])->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw $e;
        }
        return array_map(fn($result) => $this->arrayToObject($className, $result), $results);
    }

    /**
     * @param array $conditions
     * @param array $orderBy
     * @param class-string<T> $className
     * @return T|null
     */
    public function selectOne(array $conditions, array $orderBy = [], string $className = null): ?object {
        $results = $this->select($conditions, $orderBy, $className);
        if (count($results) > 1) {
            throw new \InvalidArgumentException("selectOne returned more than one result");
        }
        return $results[0] ?? null;
    }

    private function arrayToObject(string $className, array $data): object {
        $this->throwExceptionIfNotConnected();

        $obj = $this->schema->getSchema($className)->createInstanceWithoutConstructor();

        self::$origData[$obj] = $data;

        foreach ($data as $key => $value) {
            $obj->$key = $value;
        }
        return $obj;
    }


    private function resetOrigData(object $obj) {
        $this->throwExceptionIfNotConnected();

        $schema = $this->schema->getClassSchemaByObject($obj);

        self::$origData[$obj] = [];

        foreach ($schema->getColumnNames() as $key) {
            self::$origData[$obj][$key] = $obj->$key;
        }
    }

    public function changedColumns(object $oldObj): array {
        $this->throwExceptionIfNotConnected();

        $schema = $this->schema->getClassSchemaByObject($oldObj);
        $changed = [];
        foreach ($schema->getColumnNames() as $key) {
            if ($oldObj->$key !== self::$origData[$oldObj][$key]) {
                $changed[$key] = $oldObj->$key;
            }
        }
        return $changed;
    }

    public function getOriginalColumnValue(object $obj, string $column) {
        return self::$origData[$obj][$column];
    }
}
