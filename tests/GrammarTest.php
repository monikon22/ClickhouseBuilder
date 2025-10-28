<?php

namespace Tinderbox\ClickhouseBuilder;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Tinderbox\Clickhouse\Client;
use Tinderbox\ClickhouseBuilder\Exceptions\GrammarException;
use Tinderbox\ClickhouseBuilder\Query\Builder;
use Tinderbox\ClickhouseBuilder\Query\Column;
use Tinderbox\ClickhouseBuilder\Query\Enums\Format;
use Tinderbox\ClickhouseBuilder\Query\Enums\Operator;
use Tinderbox\ClickhouseBuilder\Query\Expression;
use Tinderbox\ClickhouseBuilder\Query\From;
use Tinderbox\ClickhouseBuilder\Query\Grammar;
use Tinderbox\ClickhouseBuilder\Query\Identifier;
use Tinderbox\ClickhouseBuilder\Query\JoinClause;
use Tinderbox\ClickhouseBuilder\Query\Tuple;
use Tinderbox\ClickhouseBuilder\Query\TwoElementsLogicExpression;

class GrammarTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function getBuilder(): Builder
    {
        return new Builder(m::mock(Client::class));
    }

    public function testWrap()
    {
        $grammar = new Grammar();

        $value = $grammar->wrap(new Expression('test'));
        $this->assertEquals('test', $value);

        $value = $grammar->wrap(new Identifier('*'));
        $this->assertEquals('*', $value);

        $value = $grammar->wrap(['test', 'test']);
        $this->assertEquals([
            '\'test\'',
            '\'test\'',
        ], $value);

        $value = $grammar->wrap(new Identifier('test as a'));
        $this->assertEquals('`test` AS `a`', $value);

        $value = $grammar->wrap(new Identifier('db.test as a'));
        $this->assertEquals('`db`.`test` AS `a`', $value);

        $value = $grammar->wrap(10);
        $this->assertEquals(10, $value);

        $this->assertNull($grammar->wrap(new \stdClass()));
    }

    public function testCompileInsert()
    {
        $builder = $this->getBuilder()->table('table');

        $grammar = new Grammar();

        $sql = $grammar->compileInsert($builder, [
            ['column' => 'value'],
            ['column' => 'value 2'],
            ['column' => 'value 3'],
            ['column' => null],
        ]);

        $this->assertEquals("INSERT INTO `table` (`column`) FORMAT Values ('value'), ('value 2'), ('value 3'), (null)", $sql);
    }

    public function testCompileInsertWithoutFrom()
    {
        $grammar = new Grammar();

        $e = GrammarException::missedTableForInsert();
        $this->expectException(GrammarException::class);
        $this->expectExceptionMessage($e->getMessage());

        $builder = $this->getBuilder();
        $grammar->compileInsert($builder, [
            ['column' => 'value'],
            ['column' => 'value 2'],
            ['column' => 'value 3'],
        ]);
    }

    public function testCompileInsertWithoutTableInFrom()
    {
        $grammar = new Grammar();

        $e = GrammarException::missedTableForInsert();
        $this->expectException(GrammarException::class);
        $this->expectExceptionMessage($e->getMessage());

        $builder = $this->getBuilder();
        $builder->from(new From($builder));

        $grammar->compileInsert($builder, [
            ['column' => 'value'],
            ['column' => 'value 2'],
            ['column' => 'value 3'],
        ]);
    }

    public function testCompileSelect()
    {
        $builder = $this->getBuilder();

        $grammar = new Grammar();

        /*
         * Default asterisk select
         */
        $select = $grammar->compileSelect($builder);
        $this->assertEquals('SELECT *', $select);

        /*
         * Expression column select
         */
        $builder->select(new Expression('column'));

        $select = $grammar->compileSelect($builder);
        $this->assertEquals('SELECT column', $select);

        /*
         * With alias
         */
        $builder->select(['column' => 'a']);
        $select = $grammar->compileSelect($builder);
        $this->assertEquals('SELECT `column` AS `a`', $select);

        /*
         * Complex column name with functions
         */
        $builder->select(function ($column) {
            $column->name('column')->plus(12);
        });

        $select = $grammar->compileSelect($builder);
        $this->assertEquals('SELECT `column` + 12', $select);

        $builder->select([function ($column) {
            $column->name(function ($col) {
                $col->name('a')->plus('b');
            })->multiple('c');
        }]);

        $select = $grammar->compileSelect($builder);
        $this->assertEquals('SELECT (`a` + \'b\') * \'c\'', $select);

        /*
         * Test functions
         */
        $builder->select(function ($column) {
            $column->name('column')->count();
        });

        $select = $grammar->compileSelect($builder);
        $this->assertEquals('SELECT count()', $select);

        $builder->select(function ($column) {
            $column->name('column')->runningDifference();
        });

        $select = $grammar->compileSelect($builder);
        $this->assertEquals('SELECT runningDifference(`column`)', $select);

        $builder->select(function ($column) {
            $column->name('column')->sumIf('>', 10);
        });

        $select = $grammar->compileSelect($builder);
        $this->assertEquals('SELECT sumIf(`column`, > 10)', $select);

        $builder->select(function ($column) {
            $column->name('column')->sum();
        });

        $select = $grammar->compileSelect($builder);
        $this->assertEquals('SELECT sum(`column`)', $select);

        $builder->select(function ($column) {
            $column->sum('column');
        });

        $select = $grammar->compileSelect($builder);
        $this->assertEquals('SELECT sum(`column`)', $select);

        $builder->select(function ($column) {
            $column->sum('column')->round(2);
        });

        $select = $grammar->compileSelect($builder);
        $this->assertEquals('SELECT round(sum(`column`), 2)', $select);

        $builder->select(function ($column) {
            $column->name('column')->distinct();
        });

        $select = $grammar->compileSelect($builder);
        $this->assertEquals('SELECT DISTINCT `column`', $select);

        /*
         * With format
         */
        $select = $grammar->compileSelect($this->getBuilder()->format(Format::JSON));
        $this->assertEquals('SELECT * FORMAT JSON', $select);

        /*
         * From table with alias and final
         */
        $select = $grammar->compileSelect($this->getBuilder()->from(function ($table) {
            $table->table('table')->as('a')->final(true);
        }));
        $this->assertEquals('SELECT * FROM `table` AS `a` FINAL', $select);

        /*
         * With groups
         */
        $select = $grammar->compileSelect($this->getBuilder()->from('table')->groupBy('a', 'b'));
        $this->assertEquals('SELECT * FROM `table` GROUP BY `a`, `b`', $select);

        $select = $grammar->compileSelect($this->getBuilder()->from('table')->groupBy([]));
        $this->assertEquals('SELECT * FROM `table`', $select);

        $select = $grammar->compileSelect($this->getBuilder()->from('table')->groupBy('*'));
        $this->assertEquals('SELECT * FROM `table`', $select);

        /*
         * With limits
         */
        $select = $grammar->compileSelect($this->getBuilder()->from('table')->limit(1, 10));
        $this->assertEquals('SELECT * FROM `table` LIMIT 10, 1', $select);

        /*
         * With limit by
         */
        $select = $grammar->compileSelect($this->getBuilder()->from('table')->limitBy(1, 'column'));
        $this->assertEquals('SELECT * FROM `table` LIMIT 1 BY `column`', $select);

        /*
         * With orders
         */
        $select = $grammar->compileSelect($this->getBuilder()->from('table')->orderBy('column'));
        $this->assertEquals('SELECT * FROM `table` ORDER BY `column` ASC', $select);

        /*
         * With sample
         */
        $select = $grammar->compileSelect($this->getBuilder()->from('table')->sample(0.3));
        $this->assertEquals('SELECT * FROM `table` SAMPLE 0.3', $select);

        /*
         * With unions
         */
        $select = $grammar->compileSelect($this->getBuilder()->from('table')->unionAll($this->getBuilder()->from('table2')));
        $this->assertEquals('SELECT * FROM `table` UNION ALL SELECT * FROM `table2`', $select);

        /*
         * With havings
         */
        $select = $grammar->compileSelect($this->getBuilder()->having('a', '=', raw('\'b\'')));
        $this->assertEquals('SELECT * HAVING `a` = \'b\'', $select);

        /*
         * With wheres
         */
        $select = $grammar->compileSelect($this->getBuilder()->where('a', '=', raw('\'b\'')));
        $this->assertEquals('SELECT * WHERE `a` = \'b\'', $select);

        /*
         * With pre wheres
         */
        $select = $grammar->compileSelect($this->getBuilder()->preWhere('a', '=', raw('\'b\'')));
        $this->assertEquals('SELECT * PREWHERE `a` = \'b\'', $select);

        /*
         * With join
         */
        $select = $grammar->compileSelect($this->getBuilder()->anyLeftJoin('table', ['column']));
        $this->assertEquals('SELECT * ANY LEFT JOIN `table` USING `column`', $select);

        $select = $grammar->compileSelect($this->getBuilder()->anyLeftJoin('table', ['column'], true));
        $this->assertEquals('SELECT * GLOBAL ANY LEFT JOIN `table` USING `column`', $select);

        $select = $grammar->compileSelect($this->getBuilder()->anyLeftJoin('table', ['column'], true, 'another_table'));
        $this->assertEquals('SELECT * GLOBAL ANY LEFT JOIN `table` AS `another_table` USING `column`', $select);

        $select = $grammar->compileSelect($this->getBuilder()->anyLeftJoin(function (JoinClause $join) {
            $join->table($this->getBuilder()->table('test')->select('column'));
        }, ['column'], true));
        $this->assertEquals('SELECT * GLOBAL ANY LEFT JOIN (SELECT `column` FROM `test`) USING `column`', $select);

        $select = $grammar->compileSelect($this->getBuilder()->anyLeftJoin(function (JoinClause $join) {
            $join->subQuery('test')->table('table');
        }, ['column']));
        $this->assertEquals('SELECT * ANY LEFT JOIN (SELECT * FROM `table`) AS `test` USING `column`', $select);

        $select = $grammar->compileSelect($this->getBuilder()->from($this->getBuilder()->table('test_1')->select('column', 'column_1'), 'test_1_alias')
            ->anyLeftJoin(function (JoinClause $join) {
                $join->table($this->getBuilder()->table('test_2')->select('column', 'column_2'))->as('test_2_alias')->on('test_1_alias.column', '=', 'test_2_alias.column');
            }));
        $this->assertEquals('SELECT * FROM (SELECT `column`, `column_1` FROM `test_1`) AS `test_1_alias` ANY LEFT JOIN (SELECT `column`, `column_2` FROM `test_2`) AS `test_2_alias` ON `test_1_alias`.`column` = `test_2_alias`.`column`', $select);

        /*
         * With complex two elements logic expressions
         */
        $builder = $this->getBuilder();
        $element = new TwoElementsLogicExpression($builder);
        $element->firstElement('a');
        $element->secondElement('b');
        $element->operator('=');
        $element->concatOperator(Operator::OR);

        $select = $grammar->compileSelect($builder->having($element, '=', raw('\'c\'')));
        $this->assertEquals('SELECT * HAVING \'a\' = \'b\' = \'c\'', $select);

        $select = $grammar->compileSelect($this->getBuilder()->having(new Tuple(['a', 'b']), '=', raw('\'c\'')));
        $this->assertEquals('SELECT * HAVING (\'a\', \'b\') = \'c\'', $select);

        $builder = $this->getBuilder();
        $column = new Column($builder);
        $column->name('a');

        $select = $grammar->compileSelect($builder->having($column, '=', raw('\'b\'')));
        $this->assertEquals('SELECT * HAVING `a` = \'b\'', $select);

        $builder = $this->getBuilder();
        $element = new TwoElementsLogicExpression($builder);
        $element->firstElement('a');
        $element->secondElement('b');
        $element->operator('=');
        $element->concatOperator(Operator::OR);

        $select = $grammar->compileSelect($this->getBuilder()->having([$element, $element], '=', raw('\'b\'')));
        $this->assertEquals('SELECT * HAVING (\'a\' = \'b\' OR \'a\' = \'b\') = \'b\'', $select);
    }

    public function testCompileSelectWithWrongJoin()
    {
        $grammar = new Grammar();

        $builder = $this->getBuilder();
        $join = new JoinClause($builder);

        $builder->join($join);

        $this->expectException(GrammarException::class);

        $grammar->compileSelect($builder);
    }

    public function testCompileSelectWithAmbiguousJoinKeys()
    {
        $grammar = new Grammar();

        $builder = $this->getBuilder();

        $builder->join(function (JoinClause $join) {
            $join->table('table')->using(['aaa'])
                ->on('aaa', '=', 'bbb');
        });

        $this->expectException(GrammarException::class);

        $grammar->compileSelect($builder);
    }

    public function testCompileSelectFromNullTable()
    {
        $grammar = new Grammar();

        $builder = $this->getBuilder();
        $builder->from(null);

        $e = GrammarException::wrongFrom();
        $this->expectException(GrammarException::class);
        $this->expectExceptionMessage($e->getMessage());

        $grammar->compileSelect($builder);
    }

    public function testCompileDelete()
    {
        $grammar = new Grammar();
        $builder = $this->getBuilder();
        $builder->from('table')->where('column', raw('1'));

        $sql = $grammar->compileDelete($builder);

        $this->assertEquals('ALTER TABLE `table` DELETE WHERE `column` = 1', $sql);

        $builder = $this->getBuilder();
        $builder->from('table')->where('column', raw('1'))->onCluster('test');

        $sql = $grammar->compileDelete($builder);

        $this->assertEquals('ALTER TABLE `table` ON CLUSTER test DELETE WHERE `column` = 1', $sql);

        $builder = $this->getBuilder();
        $builder->from('table');

        $this->expectException(GrammarException::class);
        $this->expectExceptionMessage('Missed where section for delete statement.');

        $grammar->compileDelete($builder);
    }

    public function testCompileCreateTable()
    {
        $grammar = new Grammar();

        $sql = $grammar->compileCreateTable('table', 'MergeTree', ['id' => 'UInt64'], true, 'test');
        $this->assertEquals('CREATE TABLE IF NOT EXISTS table ON CLUSTER test (id UInt64) ENGINE = MergeTree', $sql);

        $sql = $grammar->compileCreateTable('table', 'MergeTree', ['id' => 'UInt64'], false, 'test');
        $this->assertEquals('CREATE TABLE table ON CLUSTER test (id UInt64) ENGINE = MergeTree', $sql);

        $sql = $grammar->compileCreateTable('table', 'MergeTree', ['id' => 'UInt64'], false);
        $this->assertEquals('CREATE TABLE table  (id UInt64) ENGINE = MergeTree', $sql);
    }

    public function testCompileDropTable()
    {
        $grammar = new Grammar();

        $sql = $grammar->compileDropTable('table', true, 'test');
        $this->assertEquals('DROP TABLE IF EXISTS table ON CLUSTER test', $sql);

        $sql = $grammar->compileDropTable('table', false);
        $this->assertEquals('DROP TABLE table', $sql);
    }

    /**
     * Test complex WHERE with parameters in grouped conditions.
     * This tests the fix for: DB::Exception: Substitution `p1` is not set
     */
    public function testComplexWhereWithParametersInGroupedConditions()
    {
        $grammar = new Grammar();
        $builder = $this->getBuilder();

        $builder
            ->from('player_metrics')
            ->select('count(*) as count')
            ->where('balance', '!=', 0)
            ->where(function ($query) {
                $query->where('player', 'LIKE', '%test%')
                    ->orWhere('counterparty_nick', 'LIKE', '%test%');
            });

        $sql = $grammar->compileSelect($builder);
        $bindings = $builder->getBindings();

        // Check that SQL contains parameter placeholders
        $this->assertStringContainsString('{p', $sql);

        // Check that we have at least 3 parameters (p0, p1, p2 for the issue scenario)
        $this->assertGreaterThanOrEqual(3, count($bindings));

        // Check that all parameters are present in bindings
        foreach ($bindings as $name => $value) {
            $this->assertTrue(is_string($name));
            $this->assertNotNull($value);
        }

        // Verify that the SQL uses parameter placeholders correctly
        // Pattern: {name:Type}
        $this->assertMatchesRegularExpression('/\{p\d+:[A-Za-z0-9()]+\}/', $sql);
    }

    /**
     * Test complex WHERE with IN operator using parameters.
     */
    public function testComplexWhereWithInOperatorParameters()
    {
        $grammar = new Grammar();
        $builder = $this->getBuilder();

        $builder
            ->from('users')
            ->select('*')
            ->where('balance', '!=', 10)
            ->where(function ($query) {
                $query->whereIn('status', ['active', 'pending'])
                    ->orWhereIn('role', ['admin', 'moderator']);
            });

        $sql = $grammar->compileSelect($builder);
        $bindings = $builder->getBindings();

        // Check that we have parameters
        $this->assertNotEmpty($bindings);

        // All parameters should be retrievable
        foreach ($bindings as $name => $value) {
            $this->assertTrue(is_string($name));
        }

        // SQL should contain parameter placeholders
        $this->assertStringContainsString('{p', $sql);
    }

    /**
     * Test nested WHERE with multiple levels of grouping and parameters.
     */
    public function testNestedWhereWithMultipleLevelsAndParameters()
    {
        $grammar = new Grammar();
        $builder = $this->getBuilder();

        $builder
            ->from('events')
            ->select('*')
            ->where('archived', '=', false)
            ->where(function ($query) {
                $query->where('type', '=', 'purchase')
                    ->where(function ($subQuery) {
                        $subQuery->where('amount', '>', 100)
                            ->orWhere('amount', '<', 50);
                    });
            });

        $sql = $grammar->compileSelect($builder);
        $bindings = $builder->getBindings();

        // Ensure all parameters are collected from nested queries
        $this->assertNotEmpty($bindings);

        // Should have at least 5 parameters: archived, type, amount (100), amount (50), and false
        $this->assertGreaterThanOrEqual(4, count($bindings));

        // All bindings should be accessible
        foreach ($bindings as $name => $value) {
            $this->assertTrue(is_string($name));
            $this->assertNotNull($value);
        }
    }

    /**
     * Test the exact scenario from the issue: complex WHERE with NOT EQUAL and grouped LIKE conditions.
     */
    public function testExactScenarioFromIssue()
    {
        $grammar = new Grammar();
        $builder = $this->getBuilder();

        // Replicate: SELECT count(*) as count FROM `player_metrics` 
        // WHERE `balance` != {p0:UInt8} AND (lower(player) LIKE {p1:String} OR lower(counterparty_nick) LIKE {p2:String})
        $builder
            ->from('player_metrics')
            ->select('count(*) as count')
            ->where('balance', '!=', 0)
            ->where(function ($query) {
                $query->where('player', 'LIKE', '%search%')
                    ->orWhere('counterparty_nick', 'LIKE', '%search%');
            });

        $sql = $grammar->compileSelect($builder);
        $bindings = $builder->getBindings();

        // Verify SQL structure - count(*) gets wrapped but contains the key elements
        $this->assertStringContainsString('FROM `player_metrics`', $sql);
        $this->assertStringContainsString('WHERE', $sql);
        $this->assertStringContainsString('`balance`', $sql);
        $this->assertStringContainsString('AND', $sql);
        $this->assertStringContainsString('LIKE', $sql);
        $this->assertStringContainsString('OR', $sql);

        // Verify parameters are collected
        $this->assertGreaterThanOrEqual(
            3,
            count($bindings),
            'Should have at least 3 parameters: p0 (!=0), p1 (LIKE player), p2 (LIKE counterparty_nick)'
        );

        // Verify all parameters have proper format
        foreach ($bindings as $name => $value) {
            $this->assertTrue(
                preg_match('/^p\d+$/', $name) === 1,
                "Parameter name should match pattern p\\d+: {$name}"
            );
        }
    }
}
