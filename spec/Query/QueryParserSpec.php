<?php

namespace spec\Phore\MiniSql\Query;

use Phore\MiniSql\Query\QueryParser;
use PhpSpec\ObjectBehavior;

class QueryParserSpec extends ObjectBehavior
{
    function let(): void
    {
        $this->beConstructedWith();
    }

    function it_is_initializable(): void
    {
        $this->shouldHaveType(QueryParser::class);
    }

    function it_parses_nested_boolean_expressions(): void
    {
        $query = [
            'where' => [
                'and' => [
                    ['field' => 'status', 'op' => 'eq', 'value' => 'active'],
                    [
                        'or' => [
                            ['field' => 'age', 'op' => 'gte', 'value' => 18],
                            ['field' => 'role', 'op' => 'eq', 'value' => 'admin'],
                        ],
                    ],
                ],
            ],
        ];

        $this->parse($query)->shouldReturn($query);
    }

    function it_supports_list_and_range_operators(): void
    {
        $query = [
            'where' => [
                'and' => [
                    ['field' => 'status', 'op' => 'in', 'value' => ['active', 'pending']],
                    ['field' => 'age', 'op' => 'between', 'value' => [18, 65]],
                ],
            ],
        ];

        $this->parse($query)->shouldReturn($query);
    }

    function it_rejects_null_as_an_eq_value(): void
    {
        $this->shouldThrow(\InvalidArgumentException::class)->duringParse([
            'where' => ['field' => 'deleted_at', 'op' => 'eq', 'value' => null],
        ]);
    }

    function it_rejects_arrays_for_eq(): void
    {
        $this->shouldThrow(\InvalidArgumentException::class)->duringParse([
            'where' => ['field' => 'status', 'op' => 'eq', 'value' => ['active', 'pending']],
        ]);
    }

    function it_rejects_empty_boolean_groups(): void
    {
        $this->shouldThrow(\InvalidArgumentException::class)->duringParse([
            'where' => ['or' => []],
        ]);
    }

    function it_rejects_unknown_operators(): void
    {
        $this->shouldThrow(\InvalidArgumentException::class)->duringParse([
            'where' => ['field' => 'age', 'op' => 'approximately', 'value' => 18],
        ]);
    }

    function it_rejects_ambiguous_boolean_nodes(): void
    {
        $this->shouldThrow(\InvalidArgumentException::class)->duringParse([
            'where' => [
                'and' => [
                    ['field' => 'active', 'op' => 'eq', 'value' => true],
                ],
                'or' => [
                    ['field' => 'admin', 'op' => 'eq', 'value' => true],
                ],
            ],
        ]);
    }
}
