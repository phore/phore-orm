<?php

namespace Phore\MiniSql\Query;

/**
 * Parses and validates the plain-object query representation.
 *
 * This class deliberately does not compile SQL and does not depend on Orm.
 * Its output is a normalized array AST that can later be consumed by a query
 * compiler or converted into typed expression objects.
 */
final class QueryParser
{
    private const COMPARISON_OPERATORS = [
        'eq',
        'neq',
        'gt',
        'gte',
        'lt',
        'lte',
        'in',
        'notIn',
        'between',
        'notBetween',
        'isNull',
        'isNotNull',
        'like',
        'notLike',
        'contains',
        'startsWith',
        'endsWith',
    ];

    private const NULL_OPERATORS = ['isNull', 'isNotNull'];
    private const LIST_OPERATORS = ['in', 'notIn'];
    private const RANGE_OPERATORS = ['between', 'notBetween'];

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function parse(array $query): array
    {
        $this->assertOnlyKeys($query, ['where'], 'query');

        if (!array_key_exists('where', $query)) {
            return [];
        }

        if (!is_array($query['where']) || array_is_list($query['where'])) {
            throw new \InvalidArgumentException('query.where must be an object.');
        }

        return [
            'where' => $this->parseExpression($query['where'], 'query.where'),
        ];
    }

    /**
     * @param array<string, mixed> $expression
     * @return array<string, mixed>
     */
    private function parseExpression(array $expression, string $path): array
    {
        $booleanKeys = array_values(array_intersect(['and', 'or', 'not'], array_keys($expression)));

        if ($booleanKeys !== []) {
            if (count($booleanKeys) !== 1 || count($expression) !== 1) {
                throw new \InvalidArgumentException(
                    "$path must contain exactly one boolean operator or one comparison expression."
                );
            }

            $operator = $booleanKeys[0];
            return match ($operator) {
                'and', 'or' => $this->parseBooleanList($operator, $expression[$operator], $path),
                'not' => $this->parseNot($expression[$operator], $path),
            };
        }

        return $this->parseComparison($expression, $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseBooleanList(string $operator, mixed $value, string $path): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new \InvalidArgumentException("$path.$operator must be a non-empty array of expressions.");
        }

        $children = [];
        foreach ($value as $index => $child) {
            if (!is_array($child) || array_is_list($child)) {
                throw new \InvalidArgumentException("$path.$operator[$index] must be an expression object.");
            }
            $children[] = $this->parseExpression($child, "$path.$operator[$index]");
        }

        return [$operator => $children];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseNot(mixed $value, string $path): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException("$path.not must be an expression object.");
        }

        return ['not' => $this->parseExpression($value, "$path.not")];
    }

    /**
     * @param array<string, mixed> $expression
     * @return array<string, mixed>
     */
    private function parseComparison(array $expression, string $path): array
    {
        $this->assertOnlyKeys($expression, ['field', 'op', 'value'], $path);

        $field = $expression['field'] ?? null;
        $operator = $expression['op'] ?? null;

        if (!is_string($field) || trim($field) === '') {
            throw new \InvalidArgumentException("$path.field must be a non-empty string.");
        }

        if (!is_string($operator) || !in_array($operator, self::COMPARISON_OPERATORS, true)) {
            throw new \InvalidArgumentException("$path.op contains an unsupported comparison operator.");
        }

        $hasValue = array_key_exists('value', $expression);

        if (in_array($operator, self::NULL_OPERATORS, true)) {
            if ($hasValue) {
                throw new \InvalidArgumentException("$path.value must not be supplied for $operator.");
            }
            return ['field' => $field, 'op' => $operator];
        }

        if (!$hasValue) {
            throw new \InvalidArgumentException("$path.value is required for $operator.");
        }

        $value = $expression['value'];

        if ($value === null) {
            throw new \InvalidArgumentException(
                "$path.value must not be null; use isNull or isNotNull for null comparisons."
            );
        }

        if (in_array($operator, self::LIST_OPERATORS, true)) {
            if (!is_array($value) || !array_is_list($value) || $value === []) {
                throw new \InvalidArgumentException("$path.value must be a non-empty array for $operator.");
            }
        } elseif (in_array($operator, self::RANGE_OPERATORS, true)) {
            if (!is_array($value) || !array_is_list($value) || count($value) !== 2) {
                throw new \InvalidArgumentException("$path.value must contain exactly two values for $operator.");
            }
        } elseif (is_array($value)) {
            throw new \InvalidArgumentException(
                "$path.value must be scalar for $operator; arrays are only valid for list and range operators."
            );
        }

        return [
            'field' => $field,
            'op' => $operator,
            'value' => $value,
        ];
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $allowed
     */
    private function assertOnlyKeys(array $value, array $allowed, string $path): void
    {
        $unknown = array_diff(array_keys($value), $allowed);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                "$path contains unsupported key(s): " . implode(', ', $unknown)
            );
        }
    }
}
