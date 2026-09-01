# Architecture

## Query Language

The ORM query language is designed as a structured, JSON-serializable query AST. Queries must be representable as plain data so that the same query can be constructed locally in PHP or transported over a web API without introducing a second query language.

### Boolean `where` expressions

A query has one `where` root expression. The `where` property is not limited to one condition: it is the root of a recursive boolean expression tree.

Boolean expressions may combine an arbitrary number of child expressions using `and` and `or`, and may be nested to any supported depth. `not` negates a child expression. This allows multiple WHERE conditions and preserves SQL grouping/parentheses unambiguously.

Example:

```json
{
  "where": {
    "and": [
      {
        "field": "status",
        "op": "eq",
        "value": "active"
      },
      {
        "or": [
          {
            "field": "age",
            "op": "gte",
            "value": 18
          },
          {
            "field": "role",
            "op": "eq",
            "value": "admin"
          }
        ]
      }
    ]
  }
}
```

This represents:

```sql
WHERE status = ?
  AND (
    age >= ?
    OR role = ?
  )
```

Conceptually, the expression is a tree:

```text
AND
├── status = active
└── OR
    ├── age >= 18
    └── role = admin
```

The corresponding PHP AST should use explicit expression types, for example `AndExpr`, `OrExpr`, `NotExpr`, and `CompareExpr`. Convenience builders such as `Q::and()`, `Q::or()`, and `Q::eq()` may be provided, but they must only construct the same serializable AST and must not introduce a separate query representation.

Example PHP construction:

```php
$query = new Query(
    where: Q::and(
        Q::eq('status', 'active'),
        Q::or(
            Q::gte('age', 18),
            Q::eq('role', 'admin'),
        ),
    ),
);
```

The JSON representation must preserve the boolean grouping exactly. The compiler is responsible for translating this AST into parameterized SQL; values from the query AST must not be interpolated into SQL strings.

### Comparison operators

Comparison operators are distinct from the boolean expression operators `and`, `or`, and `not`. A comparison expression addresses one field and applies a defined, portable operator to it.

The initial portable operator set is:

| Operator | Meaning | Approximate SQL |
| --- | --- | --- |
| `eq` | equal | `= ?` |
| `neq` | not equal | `<> ?` |
| `gt` | greater than | `> ?` |
| `gte` | greater than or equal | `>= ?` |
| `lt` | less than | `< ?` |
| `lte` | less than or equal | `<= ?` |
| `in` | value is in a list | `IN (?, ...)` |
| `notIn` | value is not in a list | `NOT IN (?, ...)` |
| `between` | value is inside an inclusive range | `BETWEEN ? AND ?` |
| `notBetween` | value is outside an inclusive range | `NOT BETWEEN ? AND ?` |
| `isNull` | value is NULL | `IS NULL` |
| `isNotNull` | value is not NULL | `IS NOT NULL` |
| `like` | SQL-like pattern comparison | `LIKE ?` |
| `notLike` | negated SQL-like pattern comparison | `NOT LIKE ?` |
| `contains` | string contains value | driver-specific `LIKE` equivalent |
| `startsWith` | string starts with value | driver-specific `LIKE` equivalent |
| `endsWith` | string ends with value | driver-specific `LIKE` equivalent |

Examples:

```json
{
  "field": "age",
  "op": "gte",
  "value": 18
}
```

```json
{
  "field": "status",
  "op": "in",
  "value": ["active", "pending"]
}
```

```json
{
  "field": "created_at",
  "op": "between",
  "value": ["2026-01-01", "2026-12-31"]
}
```

Null comparisons are explicit and do not carry a `value`:

```json
{
  "field": "deleted_at",
  "op": "isNull"
}
```

`eq` with `null` must not implicitly mean `IS NULL`. Likewise, an array passed to `eq` must not implicitly mean `IN`. The wire format should remain explicit and unambiguous.

String convenience operators prevent API clients from having to know SQL wildcard syntax. For example:

```json
{
  "field": "email",
  "op": "endsWith",
  "value": "@example.com"
}
```

is preferable to requiring the client to send a SQL pattern such as `%@example.com`.

Case sensitivity should be modeled as database-independent query semantics rather than by exposing database-specific operators such as PostgreSQL `ILIKE`. A future extension may therefore support an option such as `"caseSensitive": false`, with the driver/compiler responsible for implementing the requested semantics for the target database.

A complex expression can combine all of these comparison nodes recursively:

```json
{
  "where": {
    "and": [
      {
        "field": "age",
        "op": "between",
        "value": [18, 65]
      },
      {
        "field": "status",
        "op": "in",
        "value": ["active", "pending"]
      },
      {
        "or": [
          {
            "field": "email",
            "op": "endsWith",
            "value": "@example.com"
          },
          {
            "field": "email",
            "op": "isNull"
          }
        ]
      },
      {
        "not": {
          "field": "role",
          "op": "eq",
          "value": "blocked"
        }
      }
    ]
  }
}
```

The PHP model should represent comparison operators independently from boolean AST nodes, for example:

```php
enum ComparisonOperator: string
{
    case EQ = 'eq';
    case NEQ = 'neq';
    case GT = 'gt';
    case GTE = 'gte';
    case LT = 'lt';
    case LTE = 'lte';
    case IN = 'in';
    case NOT_IN = 'notIn';
    case BETWEEN = 'between';
    case NOT_BETWEEN = 'notBetween';
    case IS_NULL = 'isNull';
    case IS_NOT_NULL = 'isNotNull';
    case LIKE = 'like';
    case NOT_LIKE = 'notLike';
    case CONTAINS = 'contains';
    case STARTS_WITH = 'startsWith';
    case ENDS_WITH = 'endsWith';
}
```

Database-specific operations such as regular expressions, JSON-path expressions, full-text search, bitwise operations, `ILIKE`, or vendor-specific search syntax are not part of the portable core. They may be introduced later as explicit capabilities/extensions without changing the semantics of the core query language.
