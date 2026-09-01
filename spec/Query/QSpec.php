<?php

namespace spec\Phore\MiniSql\Query;

use Phore\MiniSql\Query\Q;
use Phore\MiniSql\Query\QueryParser;
use PhpSpec\ObjectBehavior;

class QSpec extends ObjectBehavior
{
    function let(): void
    {
        $this->beConstructedWith();
    }

    function it_is_initializable(): void
    {
        $this->shouldHaveType(Q::class);
    }

    function it_builds_the_same_plain_data_format_as_json_queries(): void
    {
        $where = Q::and(
            Q::gte('age', 18),
            Q::in('status', ['active', 'pending']),
            Q::or(
                Q::endsWith('email', '@example.com'),
                Q::isNull('email'),
            ),
            Q::not(Q::eq('role', 'blocked')),
        );

        $expected = [
            'and' => [
                ['field' => 'age', 'op' => 'gte', 'value' => 18],
                ['field' => 'status', 'op' => 'in', 'value' => ['active', 'pending']],
                [
                    'or' => [
                        ['field' => 'email', 'op' => 'endsWith', 'value' => '@example.com'],
                        ['field' => 'email', 'op' => 'isNull'],
                    ],
                ],
                ['not' => ['field' => 'role', 'op' => 'eq', 'value' => 'blocked']],
            ],
        ];

        if ($where !== $expected) {
            throw new \RuntimeException('Q must produce the portable plain-data AST.');
        }

        $json = json_encode(['where' => $where], JSON_THROW_ON_ERROR);
        $parsed = (new QueryParser())->parseJson($json);

        if ($parsed !== ['where' => $expected]) {
            throw new \RuntimeException('A Q-built query must survive JSON serialization and parsing unchanged.');
        }
    }

    function it_builds_all_comparison_operator_shapes(): void
    {
        $expressions = [
            Q::eq('a', 1),
            Q::neq('a', 1),
            Q::gt('a', 1),
            Q::gte('a', 1),
            Q::lt('a', 1),
            Q::lte('a', 1),
            Q::in('a', [1, 2]),
            Q::notIn('a', [1, 2]),
            Q::between('a', 1, 2),
            Q::notBetween('a', 1, 2),
            Q::isNull('a'),
            Q::isNotNull('a'),
            Q::like('a', 'x%'),
            Q::notLike('a', 'x%'),
            Q::contains('a', 'x'),
            Q::startsWith('a', 'x'),
            Q::endsWith('a', 'x'),
        ];

        $parser = new QueryParser();
        foreach ($expressions as $expression) {
            $parser->parse(['where' => $expression]);
        }
    }
}
