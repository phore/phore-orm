<?php

namespace spec\Phore\MiniSql\Query;

use Phore\MiniSql\Query\QuerySqlTranslator;
use PhpSpec\ObjectBehavior;

class QuerySqlTranslatorSpec extends ObjectBehavior
{
    function let(): void
    {
        $this->beConstructedWith();
    }

    function it_is_initializable(): void
    {
        $this->shouldHaveType(QuerySqlTranslator::class);
    }

    function it_keeps_sql_injection_payloads_out_of_the_sql_statement(): void
    {
        // SECURITY: Values must never be interpolated into SQL, even when they contain SQL syntax.
        $payload = "' OR 1=1 --";

        $this->translate([
            'where' => ['field' => 'username', 'op' => 'eq', 'value' => $payload],
        ])->shouldReturn([
            'sql' => '`username` = ?',
            'params' => [$payload],
        ]);
    }

    function it_keeps_injection_payloads_in_list_values_bound_as_parameters(): void
    {
        // SECURITY: Every IN value must get its own placeholder; list contents are untrusted data.
        $payload = "admin') OR 1=1 --";

        $this->translate([
            'where' => ['field' => 'role', 'op' => 'in', 'value' => ['user', $payload]],
        ])->shouldReturn([
            'sql' => '`role` IN (?, ?)',
            'params' => ['user', $payload],
        ]);
    }

    function it_keeps_like_payloads_bound_as_parameters(): void
    {
        // SECURITY: LIKE patterns may contain SQL-looking input and still remain parameter data.
        $payload = "%_' OR 1=1 --";

        $this->translate([
            'where' => ['field' => 'email', 'op' => 'contains', 'value' => $payload],
        ])->shouldReturn([
            'sql' => '`email` LIKE ?',
            'params' => ['%' . $payload . '%'],
        ]);
    }

    function it_keeps_range_values_bound_as_parameters(): void
    {
        // SECURITY: BETWEEN bounds must not become executable SQL fragments.
        $from = '0 OR 1=1';
        $to = '100; DROP TABLE users';

        $this->translate([
            'where' => ['field' => 'age', 'op' => 'between', 'value' => [$from, $to]],
        ])->shouldReturn([
            'sql' => '`age` BETWEEN ? AND ?',
            'params' => [$from, $to],
        ]);
    }

    function it_rejects_injected_field_identifiers(): void
    {
        // SECURITY: Identifiers cannot be parameterized by PDO, so unsafe names must be rejected.
        $this->shouldThrow(\InvalidArgumentException::class)->duringTranslate([
            'where' => ['field' => 'name` OR 1=1 --', 'op' => 'eq', 'value' => 'x'],
        ]);
    }

    function it_rejects_sql_expressions_as_field_identifiers(): void
    {
        // SECURITY: Function calls and arbitrary SQL expressions are not valid field identifiers.
        $this->shouldThrow(\InvalidArgumentException::class)->duringTranslate([
            'where' => ['field' => 'IF(1=1,password,name)', 'op' => 'eq', 'value' => 'x'],
        ]);
    }

    function it_allows_safe_dotted_identifiers_and_quotes_each_part(): void
    {
        $this->translate([
            'where' => ['field' => 'users.email', 'op' => 'eq', 'value' => 'a@example.com'],
        ])->shouldReturn([
            'sql' => '`users`.`email` = ?',
            'params' => ['a@example.com'],
        ]);
    }

    function it_does_not_bind_null_operators(): void
    {
        $this->translate([
            'where' => [
                'and' => [
                    ['field' => 'deleted_at', 'op' => 'isNull'],
                    ['field' => 'verified_at', 'op' => 'isNotNull'],
                ],
            ],
        ])->shouldReturn([
            'sql' => '(`deleted_at` IS NULL) AND (`verified_at` IS NOT NULL)',
            'params' => [],
        ]);
    }

    function it_preserves_parameter_order_for_nested_boolean_expressions(): void
    {
        $this->translate([
            'where' => [
                'and' => [
                    ['field' => 'status', 'op' => 'eq', 'value' => 'active'],
                    [
                        'or' => [
                            ['field' => 'age', 'op' => 'gte', 'value' => 18],
                            ['field' => 'role', 'op' => 'neq', 'value' => 'blocked'],
                        ],
                    ],
                ],
            ],
        ])->shouldReturn([
            'sql' => '(`status` = ?) AND ((`age` >= ?) OR (`role` <> ?))',
            'params' => ['active', 18, 'blocked'],
        ]);
    }
}
