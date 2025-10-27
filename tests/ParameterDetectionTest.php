<?php

namespace Tinderbox\ClickhouseBuilder;

use PHPUnit\Framework\TestCase;
use Tinderbox\ClickhouseBuilder\Integrations\Laravel\Connection;

/**
 * Tests for parameter detection in ClickHouse parameter format.
 */
class ParameterDetectionTest extends TestCase
{
    public function getSimpleConfig()
    {
        return [
            'servers' => [
                [
                    'host'     => 'localhost',
                    'port'     => 8123,
                    'database' => 'default',
                    'username' => 'default',
                    'password' => '',
                ],
            ],
        ];
    }

    /**
     * Test that ClickHouse parameters with all keys matching p\d+ are correctly identified.
     */
    public function test_all_clickhouse_parameters_identified()
    {
        $connection = new Connection($this->getSimpleConfig());

        $reflection = new \ReflectionClass($connection);
        $method = $reflection->getMethod('isClickhouseParametersArray');
        $method->setAccessible(true);

        // Multiple ClickHouse parameters - all should match
        $params = [
            'p0' => 'value0',
            'p1' => 'value1',
            'p2' => 'value2',
            'p3' => 'value3',
        ];
        $this->assertTrue($method->invoke($connection, $params));
    }

    /**
     * Test that mixed numeric/string keys are rejected.
     */
    public function test_mixed_keys_rejected()
    {
        $connection = new Connection($this->getSimpleConfig());

        $reflection = new \ReflectionClass($connection);
        $method = $reflection->getMethod('isClickhouseParametersArray');
        $method->setAccessible(true);

        // Indexed array should be rejected
        $params = ['value0', 'value1', 'value2'];
        $this->assertFalse($method->invoke($connection, $params));
    }

    /**
     * Test that non-matching string keys are rejected.
     */
    public function test_non_matching_keys_rejected()
    {
        $connection = new Connection($this->getSimpleConfig());

        $reflection = new \ReflectionClass($connection);
        $method = $reflection->getMethod('isClickhouseParametersArray');
        $method->setAccessible(true);

        // Keys that don't match p\d+ pattern
        $params = [
            'foo' => 'value0',
            'bar' => 'value1',
        ];
        $this->assertFalse($method->invoke($connection, $params));
    }

    /**
     * Test that empty array is rejected.
     */
    public function test_empty_array_rejected()
    {
        $connection = new Connection($this->getSimpleConfig());

        $reflection = new \ReflectionClass($connection);
        $method = $reflection->getMethod('isClickhouseParametersArray');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($connection, []));
    }

    /**
     * Test conversion with proper parameter passing.
     */
    public function test_multiple_parameters_pass_through()
    {
        $connection = new Connection($this->getSimpleConfig());

        $reflection = new \ReflectionClass($connection);
        $method = $reflection->getMethod('convertLaravelPlaceholders');
        $method->setAccessible(true);

        // Multiple parameters that should be passed through
        $params = [
            'p0' => 'US',
            'p1' => 'j%',
            'p2' => 'active',
            'p3' => '2024-01-01',
        ];

        [$query, $result] = $method->invoke($connection,
            'SELECT * FROM logs WHERE server={p0:String} AND player LIKE {p1:String} AND status={p2:String} AND date>={p3:String}',
            $params
        );

        // All 4 parameters should be returned
        $this->assertEquals(4, count($result));
        $this->assertEquals('US', $result['p0']);
        $this->assertEquals('j%', $result['p1']);
        $this->assertEquals('active', $result['p2']);
        $this->assertEquals('2024-01-01', $result['p3']);
    }
}
