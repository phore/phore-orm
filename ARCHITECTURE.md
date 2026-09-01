# Architecture

## Query Language

The ORM query language is designed as a structured, JSON-serializable query AST. Queries must be representable as plain data so that the same query can be constructed locally in PHP or transported over a web API without introducing a second query language.

### Boolean `where` expressions

A query has one `where` root expression. The `where` property is not limited to one condition: it is the root of a recursive boolean expression tree.

Boolean expressions may combine an arbitrary number of child expressions using `and` and `or`, and may be nested to any supported depth. This allows multiple WHERE conditions and preserves SQL grouping/parentheses unambiguously.

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
