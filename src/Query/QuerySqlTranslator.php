<?php

namespace Phore\MiniSql\Query;

/**
 * Translates a validated structured query into a SQL WHERE fragment and
 * positional bind parameters.
 *
 * The translator deliberately has no schema or PDO dependency. Field names
 * are restricted to simple/dotted identifiers and quoted as SQL identifiers;
 * all values are emitted as bind parameters.
 */
final class QuerySqlTranslator
{
    /**
     * @param array<string, mixed> $query A query normalized by QueryParser.
     * @return array{sql: string, params: list<mixed>}
     */
    public function translate(array $query): array
    {
        if (!array_key_exists('where', $query)) {
            return ['sql' => '', 'params' => []];
        }

        $params = [];
        $sql = $this->translateExpression($query['where'], $params);

        return ['sql' => $sql, 'params' => $params];
    }

    /** @param list<mixed> $params */
    private function translateExpression(array $expression, array &$params): string
    {
        if (isset($expression['and'])) {
            return $this->translateBooleanList('AND', $expression['and'], $params);
        }
        if (isset($expression['or'])) {
            return $this->translateBooleanList('OR', $expression['or'], $params);
        }
        if (isset($expression['not'])) {
            return 'NOT (' . $this->translateExpression($expression['not'], $params) . ')';
        }

        return $this->translateComparison($expression, $params);
    }

    /** @param list<array<string, mixed>> $expressions @param list<mixed> $params */
    private function translateBooleanList(string $operator, array $expressions, array &$params): string
    {
        $parts = array_map(
            fn(array $expression): string => '(' . $this->translateExpression($expression, $params) . ')',
            $expressions
        );

        return implode(" $operator ", $parts);
    }

    /** @param list<mixed> $params */
    private function translateComparison(array $expression, array &$params): string
    {
        // SECURITY: Field names become SQL syntax and cannot be represented by PDO placeholders.
        // Always pass them through the strict identifier validator before composing the statement.
        $field = $this->quoteIdentifier($expression['field']);
        $operator = $expression['op'];
        $value = $expression['value'] ?? null;

        return match ($operator) {
            'eq' => $this->bind($field, '=', $value, $params),
            'neq' => $this->bind($field, '<>', $value, $params),
            'gt' => $this->bind($field, '>', $value, $params),
            'gte' => $this->bind($field, '>=', $value, $params),
            'lt' => $this->bind($field, '<', $value, $params),
            'lte' => $this->bind($field, '<=', $value, $params),
            'like' => $this->bind($field, 'LIKE', $value, $params),
            'notLike' => $this->bind($field, 'NOT LIKE', $value, $params),
            'contains' => $this->bind($field, 'LIKE', '%' . $value . '%', $params),
            'startsWith' => $this->bind($field, 'LIKE', $value . '%', $params),
            'endsWith' => $this->bind($field, 'LIKE', '%' . $value, $params),
            'isNull' => "$field IS NULL",
            'isNotNull' => "$field IS NOT NULL",
            'in' => $this->bindList($field, 'IN', $value, $params),
            'notIn' => $this->bindList($field, 'NOT IN', $value, $params),
            'between' => $this->bindRange($field, 'BETWEEN', $value, $params),
            'notBetween' => $this->bindRange($field, 'NOT BETWEEN', $value, $params),
            default => throw new \InvalidArgumentException("Unsupported query operator: $operator"),
        };
    }

    /** @param list<mixed> $params */
    private function bind(string $field, string $operator, mixed $value, array &$params): string
    {
        // SECURITY: Never interpolate query values into SQL. Values are returned separately and
        // must be passed to PDO as prepared-statement parameters by the caller.
        $params[] = $value;
        return "$field $operator ?";
    }

    /** @param list<mixed> $values @param list<mixed> $params */
    private function bindList(string $field, string $operator, array $values, array &$params): string
    {
        // SECURITY: Only the number of placeholders is generated; list values never enter SQL text.
        array_push($params, ...$values);
        return "$field $operator (" . implode(', ', array_fill(0, count($values), '?')) . ')';
    }

    /** @param array{0: mixed, 1: mixed} $values @param list<mixed> $params */
    private function bindRange(string $field, string $operator, array $values, array &$params): string
    {
        // SECURITY: Range bounds are data and therefore remain positional bind parameters.
        $params[] = $values[0];
        $params[] = $values[1];
        return "$field $operator ? AND ?";
    }

    private function quoteIdentifier(string $identifier): string
    {
        // SECURITY: Identifier injection is the main non-bindable input boundary. Restrict every
        // dotted identifier segment to a conservative allow-list before adding identifier quotes.
        $parts = explode('.', $identifier);
        foreach ($parts as $part) {
            if ($part === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $part) !== 1) {
                throw new \InvalidArgumentException("Invalid SQL identifier: $identifier");
            }
        }

        return implode('.', array_map(fn(string $part): string => "`$part`", $parts));
    }
}
