# Phore MiniSql

## Overview
Phore MiniSql is a lightweight ORM library for PHP, providing an easy-to-use interface for database operations such as create, read, update, delete (CRUD), and schema management. This README provides detailed usage instructions, including entity definitions, handling operations, indexes, foreign keys, and table/connection maintenance.

## Installation
To install Phore MiniSql, use Composer:

```bash
composer require phore/minisql
```

## Defining Entities
Entities are defined as PHP classes with public properties representing the columns of the corresponding database table. Each entity class must implement a static `__schema` method that returns an `OrmClassSchema` object.

```php
namespace App\Entity;

use Phore\MiniSql\Schema\OrmClassSchema;

class User
{
    public int $id;
    public string $name;
    public string $email;

    public static function __schema(): OrmClassSchema
    {
        return new OrmClassSchema(
            tableName: 'users',
            primaryKey: 'id',
            autoincrement: true,
            columns: [
                'id' => 'int',
                'name' => 'varchar(255)',
                'email' => 'varchar(255)'
            ]
        );
    }
}
```

## Usage
### Connecting to the Database
To connect to the database, create an instance of the `Orm` class and provide the DSN and entity classes.

```php
use Phore\MiniSql\Orm;
use App\Entity\User;

$orm = new Orm([User::class], 'mysql:host=localhost;dbname=testdb;user=root;password=root');
$orm->connect();
```

### Creating Records
To create a new record, instantiate the entity class, set its properties, and call the `create` method.

```php
$user = new User();
$user->name = 'John Doe';
$user->email = 'john.doe@example.com';
$orm->create($user);
```

### Reading Records
To read a record by its primary key, use the `read` method.

```php
$user = $orm->withClass(User::class)->read(1);
```

### Updating Records
To update a record, modify its properties and call the `update` method.

```php
$user->name = 'Jane Doe';
$orm->update($user);
```

### Deleting Records
To delete a record, call the `delete` method.

```php
$orm->delete($user);
```

### Listing All Records
To list all records of an entity, use the `listAll` method.

```php
$users = $orm->withClass(User::class)->listAll();
```

### Selecting Records with Conditions
To select records with specific conditions, use the `select` method.

```php
$users = $orm->withClass(User::class)->select(['name' => 'Jane Doe']);
```

## Indexes
Indexes can be defined in the `OrmClassSchema` using the `indexes` property.

```php
public static function __schema(): OrmClassSchema
{
    return new OrmClassSchema(
        tableName: 'users',
        primaryKey: 'id',
        autoincrement: true,
        columns: [
            'id' => 'int',
            'name' => 'varchar(255)',
            'email' => 'varchar(255)'
        ],
        indexes: [
            'idx_name' => ['name'],
            'idx_email' => ['email']
        ]
    );
}
```

## Foreign Keys
Foreign keys can be defined in the `OrmClassSchema` using the `foreignKeys` property.

```php
use Phore\MiniSql\Schema\OrmForeignKey;

public static function __schema(): OrmClassSchema
{
    return new OrmClassSchema(
        tableName: 'orders',
        primaryKey: 'id',
        autoincrement: true,
        columns: [
            'id' => 'int',
            'user_id' => 'int',
            'product_id' => 'int'
        ],
        foreignKeys: [
            new OrmForeignKey('user_id', 'users', 'id'),
            new OrmForeignKey('product_id', 'products', 'id')
        ]
    );
}
```

## Maintaining Tables and Connections
### Updating Schema
To update the database schema based on the defined entities, use the `updateSchema` method.

```php
$orm->updateSchema();
```

### Dropping All Tables
To drop all tables in the database, use the `dropAllTables` method.

```php
$orm->getDriver()->getSchemaUpdater()->dropAllTables();
```

## Conclusion
Phore MiniSql provides a simple and efficient way to manage database operations in PHP. By defining entities and using the provided methods, you can easily perform CRUD operations, manage indexes and foreign keys, and maintain your database schema.