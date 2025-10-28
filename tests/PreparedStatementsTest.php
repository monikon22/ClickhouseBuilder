<?php

namespace Tinderbox\ClickhouseBuilder;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Tinderbox\Clickhouse\Client;
use Tinderbox\ClickhouseBuilder\Query\Builder;
use Tinderbox\ClickhouseBuilder\Query\Parameter;

class PreparedStatementsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        // Reset parameter counter for each test
        $reflection = new \ReflectionClass(\Tinderbox\ClickhouseBuilder\Query\BaseBuilder::class);
        $property = $reflection->getProperty('parameterCounter');
        $property->setAccessible(true);
        $property->setValue(null, 0);
    }

    public function getBuilder(): Builder
    {
        return new Builder(m::mock(Client::class));
    }

    public function test_parameter_creation()
    {
        $param = new Parameter('id', 123, 'UInt32');

        $this->assertEquals('id', $param->getName());
        $this->assertEquals(123, $param->getValue());
        $this->assertEquals('UInt32', $param->getType());
        $this->assertEquals('{id:UInt32}', $param->getPlaceholder());
        $this->assertEquals(['param_id' => 123], $param->toHttpParam());
    }

    public function test_parameter_type_inference_integers()
    {
        // Positive integers
        $param1 = new Parameter('small', 100);
        $this->assertEquals('UInt8', $param1->getType());

        $param2 = new Parameter('medium', 50000);
        $this->assertEquals('UInt16', $param2->getType());

        $param3 = new Parameter('large', 5000000);
        $this->assertEquals('UInt32', $param3->getType());

        // Negative integers
        $param4 = new Parameter('neg_small', -50);
        $this->assertEquals('Int8', $param4->getType());

        $param5 = new Parameter('neg_medium', -20000);
        $this->assertEquals('Int16', $param5->getType());

        $param6 = new Parameter('neg_large', -2000000);
        $this->assertEquals('Int32', $param6->getType());
    }

    public function test_parameter_type_inference_other_types()
    {
        $paramFloat = new Parameter('price', 19.99);
        $this->assertEquals('Float64', $paramFloat->getType());

        $paramString = new Parameter('name', 'John Doe');
        $this->assertEquals('String', $paramString->getType());

        $paramBool = new Parameter('active', true);
        $this->assertEquals('UInt8', $paramBool->getType());

        $paramArray = new Parameter('ids', [1, 2, 3]);
        $this->assertEquals('Array(UInt8)', $paramArray->getType());
    }

    public function test_bind_value()
    {
        $builder = $this->getBuilder();

        $builder->bindValue('id', 123, 'UInt32');

        $params = $builder->getParameters();
        $this->assertCount(1, $params);
        $this->assertInstanceOf(Parameter::class, $params['id']);
        $this->assertEquals(123, $params['id']->getValue());
        $this->assertEquals('UInt32', $params['id']->getType());
    }

    public function test_bind_alias()
    {
        $builder = $this->getBuilder();

        $builder->bind('name', 'Alice');

        $this->assertTrue($builder->hasParameter('name'));
        $this->assertEquals('Alice', $builder->getParameter('name')->getValue());
    }

    public function test_set_parameters()
    {
        $builder = $this->getBuilder();

        $builder->setParameters([
            'id' => 456,
            'name' => 'Bob',
            'active' => true,
        ], [
            'id' => 'UInt32',
            'active' => 'UInt8',
        ]);

        $this->assertCount(3, $builder->getParameters());
        $this->assertEquals('UInt32', $builder->getParameter('id')->getType());
        $this->assertEquals('String', $builder->getParameter('name')->getType());
        $this->assertEquals('UInt8', $builder->getParameter('active')->getType());
    }

    public function test_has_parameter()
    {
        $builder = $this->getBuilder();

        $this->assertFalse($builder->hasParameter('id'));

        $builder->bindValue('id', 789);

        $this->assertTrue($builder->hasParameter('id'));
    }

    public function test_get_parameter()
    {
        $builder = $this->getBuilder();

        $builder->bindValue('id', 999);

        $param = $builder->getParameter('id');
        $this->assertInstanceOf(Parameter::class, $param);
        $this->assertEquals(999, $param->getValue());

        $missing = $builder->getParameter('nonexistent');
        $this->assertNull($missing);
    }

    public function test_clear_parameters()
    {
        $builder = $this->getBuilder();

        $builder->bindValue('id', 111);
        $builder->bindValue('name', 'Charlie');

        $this->assertCount(2, $builder->getParameters());

        $builder->clearParameters();

        $this->assertCount(0, $builder->getParameters());
    }

    public function test_get_bindings()
    {
        $builder = $this->getBuilder();

        $builder->bindValue('id', 123, 'UInt32');
        $builder->bindValue('name', 'David');
        $builder->bindValue('active', true, 'UInt8');

        $bindings = $builder->getBindings();

        $this->assertEquals([
            'id' => 123,
            'name' => 'David',
            'active' => '1',
        ], $bindings);
    }

    public function test_get_http_parameters_deprecated()
    {
        $builder = $this->getBuilder();

        $builder->bindValue('id', 123, 'UInt32');

        // Test backward compatibility
        $httpParams = $builder->getHttpParameters();

        $this->assertEquals([
            'id' => 123,
        ], $httpParams);
    }

    public function test_parameter_in_where_clause()
    {
        $builder = $this->getBuilder();

        $param = new Parameter('user_id', 42, 'UInt32');

        $builder->table('users')
            ->select('*')
            ->where('id', '=', $param);

        $sql = $builder->toSql();

        $this->assertStringContainsString('{user_id:UInt32}', $sql);
        $this->assertEquals("SELECT * FROM `users` WHERE `id` = {user_id:UInt32}", $sql);
    }

    public function test_multiple_parameters_in_query()
    {
        $builder = $this->getBuilder();

        $param1 = new Parameter('min_age', 18, 'UInt8');
        $param2 = new Parameter('max_age', 65, 'UInt8');
        $param3 = new Parameter('country', 'USA', 'String');

        $builder->table('users')
            ->select('name', 'age', 'country')
            ->where('age', '>=', $param1)
            ->where('age', '<=', $param2)
            ->where('country', '=', $param3);

        $sql = $builder->toSql();

        $this->assertStringContainsString('{min_age:UInt8}', $sql);
        $this->assertStringContainsString('{max_age:UInt8}', $sql);
        $this->assertStringContainsString('{country:String}', $sql);
    }

    public function test_parameter_with_bind_and_where()
    {
        $builder = $this->getBuilder();

        $builder->table('products')
            ->select('name', 'price')
            ->where('price', '>', new Parameter('min_price', 100, 'Float64'))
            ->bindValue('min_price', 100, 'Float64');

        $sql = $builder->toSql();
        $bindings = $builder->getBindings();

        $this->assertStringContainsString('{min_price:Float64}', $sql);
        $this->assertEquals(['min_price' => 100], $bindings);
    }

    public function test_parameter_in_select_expression()
    {
        $builder = $this->getBuilder();

        $param = new Parameter('multiplier', 2, 'UInt8');

        $builder->table('sales')
            ->select(\raw('revenue * ' . $param->getPlaceholder() . ' as doubled_revenue'));

        $sql = $builder->toSql();

        $this->assertStringContainsString('{multiplier:UInt8}', $sql);
    }

    public function test_parameter_array_value()
    {
        $param = new Parameter('ids', [1, 2, 3, 4, 5], 'Array(UInt32)');

        $this->assertEquals('Array(UInt32)', $param->getType());
        $this->assertEquals('[1,2,3,4,5]', $param->toHttpParam()['param_ids']);
    }

    public function test_parameter_string_array()
    {
        $param = new Parameter('names', ['Alice', 'Bob', 'Charlie'], 'Array(String)');

        $httpParam = $param->toHttpParam();
        $this->assertEquals("['Alice','Bob','Charlie']", $httpParam['param_names']);
    }

    public function test_parameter_boolean_formatting()
    {
        $paramTrue = new Parameter('is_active', true);
        $paramFalse = new Parameter('is_deleted', false);

        $this->assertEquals('1', $paramTrue->toHttpParam()['param_is_active']);
        $this->assertEquals('0', $paramFalse->toHttpParam()['param_is_deleted']);
    }

    public function test_from_placeholder_parsing()
    {
        $param = Parameter::fromPlaceholder('{id:UInt32}');

        $this->assertNotNull($param);
        $this->assertEquals('id', $param->getName());
        $this->assertEquals('UInt32', $param->getType());
    }

    public function test_from_placeholder_with_array_type()
    {
        $param = Parameter::fromPlaceholder('{ids:Array(UInt32)}');

        $this->assertNotNull($param);
        $this->assertEquals('ids', $param->getName());
        $this->assertEquals('Array(UInt32)', $param->getType());
    }

    public function test_from_placeholder_invalid()
    {
        $param = Parameter::fromPlaceholder('invalid');

        $this->assertNull($param);
    }

    public function test_parameter_to_string()
    {
        $param = new Parameter('test', 123, 'UInt32');

        $this->assertEquals('{test:UInt32}', (string) $param);
    }

    public function test_complex_query_with_multiple_parameters()
    {
        $builder = $this->getBuilder();

        $builder->table('orders')
            ->select('order_id', 'customer_id', 'total', 'created_at')
            ->where('total', '>=', new Parameter('min_total', 100, 'Float64'))
            ->where('created_at', '>=', new Parameter('start_date', '2024-01-01', 'String'))
            ->where('created_at', '<=', new Parameter('end_date', '2024-12-31', 'String'))
            ->where('status', '=', new Parameter('status', 'completed', 'String'))
            ->bindValue('min_total', 100, 'Float64')
            ->bindValue('start_date', '2024-01-01')
            ->bindValue('end_date', '2024-12-31')
            ->bindValue('status', 'completed');

        $sql = $builder->toSql();
        $bindings = $builder->getBindings();

        $this->assertStringContainsString('{min_total:Float64}', $sql);
        $this->assertStringContainsString('{start_date:String}', $sql);
        $this->assertStringContainsString('{end_date:String}', $sql);
        $this->assertStringContainsString('{status:String}', $sql);

        $this->assertEquals([
            'min_total' => 100,
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'status' => 'completed',
        ], $bindings);
    }

    // ============================================
    // NEW TESTS FOR AUTOMATIC PARAMETER CONVERSION
    // ============================================

    public function test_auto_convert_scalar_value_in_where()
    {
        $builder = $this->getBuilder();

        $builder->table('users')
            ->select('*')
            ->where('id', '=', 42);

        $sql = $builder->toSql();
        $bindings = $builder->getBindings();

        // Should automatically create parameter with auto-generated name
        $this->assertStringContainsString('{p0:', $sql);
        $this->assertCount(1, $bindings);
        $this->assertEquals(42, $bindings['p0']);
    }

    public function test_auto_convert_multiple_scalar_values()
    {
        $builder = $this->getBuilder();

        $builder->table('users')
            ->select('*')
            ->where('age', '>=', 18)
            ->where('age', '<=', 65)
            ->where('country', '=', 'USA');

        $sql = $builder->toSql();
        $bindings = $builder->getBindings();

        $this->assertStringContainsString('{p0:', $sql);
        $this->assertStringContainsString('{p1:', $sql);
        $this->assertStringContainsString('{p2:', $sql);

        $this->assertCount(3, $bindings);
        $this->assertEquals(18, $bindings['p0']);
        $this->assertEquals(65, $bindings['p1']);
        $this->assertEquals('USA', $bindings['p2']);
    }

    public function test_auto_convert_preserves_manual_parameters()
    {
        $builder = $this->getBuilder();

        $manualParam = new Parameter('user_id', 100, 'UInt32');

        $builder->table('users')
            ->select('*')
            ->where('id', '=', $manualParam)  // Manual Parameter
            ->where('status', '=', 'active');  // Auto-converted

        $sql = $builder->toSql();
        $bindings = $builder->getBindings();

        $this->assertStringContainsString('{user_id:UInt32}', $sql);
        $this->assertStringContainsString('{p0:', $sql);

        $this->assertCount(2, $bindings);
        $this->assertEquals(100, $bindings['user_id']);
        $this->assertEquals('active', $bindings['p0']);
    }

    public function test_auto_convert_does_not_affect_expressions()
    {
        $builder = $this->getBuilder();

        $builder->table('users')
            ->select('*')
            ->where('created_at', '>', \raw('NOW() - INTERVAL 1 DAY'));

        $sql = $builder->toSql();
        $bindings = $builder->getBindings();

        // Expression should not be converted to parameter
        $this->assertStringContainsString('NOW() - INTERVAL 1 DAY', $sql);
        $this->assertCount(0, $bindings);
    }

    public function test_auto_convert_with_prewhere()
    {
        $builder = $this->getBuilder();

        $builder->table('events')
            ->select('*')
            ->preWhere('date', '=', '2024-01-01')
            ->where('type', '=', 'click');

        $sql = $builder->toSql();
        $bindings = $builder->getBindings();

        $this->assertStringContainsString('{p0:', $sql);
        $this->assertStringContainsString('{p1:', $sql);
        $this->assertCount(2, $bindings);
    }

    public function test_auto_convert_with_having()
    {
        $builder = $this->getBuilder();

        $builder->table('sales')
            ->select(\raw('product_id'), \raw('SUM(amount) as total'))
            ->groupBy('product_id')
            ->having(\raw('SUM(amount)'), '>', 1000);

        $sql = $builder->toSql();
        $bindings = $builder->getBindings();

        $this->assertStringContainsString('{p0:', $sql);
        $this->assertCount(1, $bindings);
        $this->assertEquals(1000, $bindings['p0']);
    }

    public function test_auto_convert_preserves_null()
    {
        $builder = $this->getBuilder();

        $builder->table('users')
            ->select('*')
            ->where('deleted_at', '=', null);

        $sql = $builder->toSql();
        $bindings = $builder->getBindings();

        // null should be rendered as null keyword in SQL
        $this->assertStringContainsString('= null', $sql);
        $this->assertCount(0, $bindings);
    }

    public function test_auto_convert_with_between()
    {
        $builder = $this->getBuilder();

        $builder->table('products')
            ->select('*')
            ->whereBetween('price', [10.00, 100.00]);

        $sql = $builder->toSql();
        $bindings = $builder->getBindings();

        // Both values in BETWEEN should be converted
        $this->assertStringContainsString('{p0:', $sql);
        $this->assertStringContainsString('{p1:', $sql);
        $this->assertCount(2, $bindings);
        $this->assertEquals(10.00, $bindings['p0']);
        $this->assertEquals(100.00, $bindings['p1']);
    }

    public function test_no_need_for_manual_binding()
    {
        $builder = $this->getBuilder();

        // Previously, users had to do:
        // ->where('id', '=', new Parameter('user_id', 42))
        // ->bind('user_id', 42)
        //
        // Now they can just do:
        $builder->table('users')
            ->select('*')
            ->where('id', '=', 42);

        $sql = $builder->toSql();
        $bindings = $builder->getBindings();

        // Automatically creates parameter and binds it
        $this->assertNotEmpty($bindings);
        $this->assertStringContainsString('{p0:', $sql);
    }

    public function test_complex_query_with_auto_conversion()
    {
        $builder = $this->getBuilder();

        $builder->table('orders')
            ->select('*')
            ->where('customer_id', '=', 123)
            ->where('status', '=', 'pending')
            ->where('total', '>=', 50.00)
            ->where('created_at', '>=', '2024-01-01');

        $sql = $builder->toSql();
        $bindings = $builder->getBindings();

        $this->assertCount(4, $bindings);
        $this->assertEquals(123, $bindings['p0']);
        $this->assertEquals('pending', $bindings['p1']);
        $this->assertEquals(50.00, $bindings['p2']);
        $this->assertEquals('2024-01-01', $bindings['p3']);
    }

    public function test_parameter_value_update()
    {
        $param = new Parameter('id', 100);

        $this->assertEquals(100, $param->getValue());

        $param->setValue(200);

        $this->assertEquals(200, $param->getValue());
    }

    public function test_parameter_type_update()
    {
        $param = new Parameter('value', 100);

        $this->assertEquals('UInt8', $param->getType());

        $param->setType('UInt32');

        $this->assertEquals('UInt32', $param->getType());
        $this->assertEquals('{value:UInt32}', $param->getPlaceholder());
    }
}
