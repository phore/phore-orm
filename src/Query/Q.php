<?php

namespace Phore\MiniSql\Query;

/**
 * Convenience builders for the portable query data format.
 *
 * Q is intentionally only syntactic sugar for PHP callers. Every method
 * returns plain arrays containing only JSON-serializable data. The resulting
 * structures are exactly the same structures that QueryParser accepts from
 * ordinary decoded JSON input; using Q must never be required to construct a
 * valid query.
 */
final class Q
{
    /** @return array<string, mixed> */
    public static function eq(string $field, mixed $value): array
    {
        return self::comparison($field, 'eq', $value);
    }

    /** @return array<string, mixed> */
    public static function neq(string $field, mixed $value): array
    {
        return self::comparison($field, 'neq', $value);
    }

    /** @return array<string, mixed> */
    public static function gt(string $field, mixed $value): array
    {
        return self::comparison($field, 'gt', $value);
    }

    /** @return array<string, mixed> */
    public static function gte(string $field, mixed $value): array
    {
        return self::comparison($field, 'gte', $value);
    }

    /** @return array<string, mixed> */
    public static function lt(string $field, mixed $value): array
    {
        return self::comparison($field, 'lt', $value);
    }

    /** @return array<string, mixed> */
    public static function lte(string $field, mixed $value): array
    {
        return self::comparison($field, 'lte', $value);
    }

    /** @param list<mixed> $values
     *  @return array<string, mixed>
     */
    public static function in(string $field, array $values): array
    {
        return self::comparison($field, 'in', $values);
    }

    /** @param list<mixed> $values
     *  @return array<string, mixed>
     */
    public static function notIn(string $field, array $values): array
    {
        return self::comparison($field, 'notIn', $values);
    }

    /** @return array<string, mixed> */
    public static function between(string $field, mixed $from, mixed $to): array
    {
        return self::comparison($field, 'between', [$from, $to]);
    }

    /** @return array<string, mixed> */
    public static function notBetween(string $field, mixed $from, mixed $to): array
    {
        return self::comparison($field, 'notBetween', [$from, $to]);
    }

    /** @return array<string, string> */
    public static function isNull(string $field): array
    {
        return ['field' => $field, 'op' => 'isNull'];
    }

    /** @return array<string, string> */
    public static function isNotNull(string $field): array
    {
        return ['field' => $field, 'op' => 'isNotNull'];
    }

    /** @return array<string, mixed> */
    public static function like(string $field, string $value): array
    {
        return self::comparison($field, 'like', $value);
    }

    /** @return array<string, mixed> */
    public static function notLike(string $field, string $value): array
    {
        return self::comparison($field, 'notLike', $value);
    }

    /** @return array<string, mixed> */
    public static function contains(string $field, string $value): array
    {
        return self::comparison($field, 'contains', $value);
    }

    /** @return array<string, mixed> */
    public static function startsWith(string $field, string $value): array
    {
        return self::comparison($field, 'startsWith', $value);
    }

    /** @return array<string, mixed> */
    public static function endsWith(string $field, string $value): array
    {
        return self::comparison($field, 'endsWith', $value);
    }

    /**
     * @param array<string, mixed> ...$expressions
     * @return array{and: list<array<string, mixed>>}
     */
    public static function and(array ...$expressions): array
    {
        return ['and' => $expressions];
    }

    /**
     * @param array<string, mixed> ...$expressions
     * @return array{or: list<array<string, mixed>>}
     */
    public static function or(array ...$expressions): array
    {
        return ['or' => $expressions];
    }

    /**
     * @param array<string, mixed> $expression
     * @return array{not: array<string, mixed>}
     */
    public static function not(array $expression): array
    {
        return ['not' => $expression];
    }

    /** @return array<string, mixed> */
    private static function comparison(string $field, string $operator, mixed $value): array
    {
        return [
            'field' => $field,
            'op' => $operator,
            'value' => $value,
        ];
    }
}
