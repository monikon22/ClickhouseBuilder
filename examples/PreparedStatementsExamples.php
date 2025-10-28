<?php

/**
 * Examples of using Prepared Statements with ClickHouse Builder
 *
 * This file demonstrates various ways to use prepared statements
 * for secure and efficient database queries.
 */

use Tinderbox\ClickhouseBuilder\Query\Parameter;
use Illuminate\Support\Facades\DB;

// Example 1: Basic usage with where clause
function example1_basic_usage()
{
    $userId = 42;

    $users = DB::connection('clickhouse')
        ->table('users')
        ->select('id', 'name', 'email')
        ->where('id', '=', new Parameter('user_id', $userId, 'UInt32'))
        ->get();

    return $users;
}

// Example 2: Multiple parameters
function example2_multiple_parameters()
{
    $minAge = 18;
    $maxAge = 65;
    $country = 'USA';

    $users = DB::connection('clickhouse')
        ->table('users')
        ->select('name', 'age', 'country')
        ->where('age', '>=', new Parameter('min_age', $minAge, 'UInt8'))
        ->where('age', '<=', new Parameter('max_age', $maxAge, 'UInt8'))
        ->where('country', '=', new Parameter('country', $country, 'String'))
        ->get();

    return $users;
}

// Example 3: Array parameters
function example3_array_parameters()
{
    $statusList = ['active', 'pending', 'processing'];

    $orders = DB::connection('clickhouse')
        ->table('orders')
        ->select('order_id', 'status', 'total')
        ->where('status', 'IN', new Parameter('statuses', $statusList, 'Array(String)'))
        ->get();

    return $orders;
}

// Example 4: Date range query
function example4_date_range()
{
    $startDate = '2024-01-01';
    $endDate = '2024-12-31';

    $events = DB::connection('clickhouse')
        ->table('events')
        ->select('event_id', 'event_type', 'created_at')
        ->where('created_at', '>=', new Parameter('start_date', $startDate, 'String'))
        ->where('created_at', '<=', new Parameter('end_date', $endDate, 'String'))
        ->get();

    return $events;
}

// Example 5: Complex query with multiple conditions
function example5_complex_query()
{
    $minPrice = 100.00;
    $maxPrice = 1000.00;
    $categories = ['electronics', 'computers', 'phones'];
    $minStock = 10;

$products = DB::connection('clickhouse')
    ->table('products')
    ->select('product_id', 'name', 'price', 'category', 'stock')
    ->where('price', '>=', new Parameter('min_price', $minPrice, 'Float64'))
    ->where('price', '<=', new Parameter('max_price', $maxPrice, 'Float64'))
    ->where('category', 'IN', new Parameter('categories', $categories, 'Array(String)'))
    ->where('stock', '>=', new Parameter('min_stock', $minStock, 'UInt32'))
    ->orderBy('price', 'asc')
    ->limit(100)
    ->get();    return $products;
}

// Example 6: Automatic type inference
function example6_auto_type_inference()
{
    // No need to specify types - they will be inferred automatically
    $builder = DB::connection('clickhouse')
        ->table('users')
        ->select('*')
        ->where('id', '=', new Parameter('id', 123))          // Infers UInt8
        ->where('name', '=', new Parameter('name', 'Alice'))  // Infers String
        ->where('active', '=', new Parameter('active', true)) // Infers UInt8
;

    return $builder->get();
}

// Example 7: Reusing parameters
function example7_reusing_parameters()
{
    $userId = 42;

    // Create parameter once
    $userIdParam = new Parameter('user_id', $userId, 'UInt32');

    // Use in multiple queries
    $orders = DB::connection('clickhouse')
        ->table('orders')
        ->select('*')
        ->where('user_id', '=', $userIdParam)
        ->get();

    $payments = DB::connection('clickhouse')
        ->table('payments')
        ->select('*')
        ->where('user_id', '=', $userIdParam)
        ->get();

    return [
        'orders' => $orders,
        'payments' => $payments,
    ];
}

// Example 8: Dynamic filtering
function example8_dynamic_filtering(array $filters)
{
    $builder = DB::connection('clickhouse')->table('products');

    $parameters = [];
    $types = [];

    if (isset($filters['min_price'])) {
        $builder->where('price', '>=', new Parameter('min_price', $filters['min_price'], 'Float64'));
        $parameters['min_price'] = $filters['min_price'];
        $types['min_price'] = 'Float64';
    }

    if (isset($filters['max_price'])) {
        $builder->where('price', '<=', new Parameter('max_price', $filters['max_price'], 'Float64'));
        $parameters['max_price'] = $filters['max_price'];
        $types['max_price'] = 'Float64';
    }

    if (isset($filters['category'])) {
        $builder->where('category', '=', new Parameter('category', $filters['category'], 'String'));
        $parameters['category'] = $filters['category'];
    }

    if (isset($filters['in_stock']) && $filters['in_stock']) {
        $builder->where('stock', '>', new Parameter('zero', 0, 'UInt32'));
        $parameters['zero'] = 0;
        $types['zero'] = 'UInt32';
    }

    $builder->setParameters($parameters, $types);

    return $builder->get();
}

// Example 9: Aggregation with parameters
function example9_aggregation()
{
    $startDate = '2024-01-01';
    $minTotal = 100;

    $stats = DB::connection('clickhouse')
        ->table('orders')
        ->select(\raw('COUNT(*) as order_count'), \raw('SUM(total) as total_sum'))
        ->where('created_at', '>=', new Parameter('start_date', $startDate, 'String'))
        ->where('total', '>=', new Parameter('min_total', $minTotal, 'Float64'))
        ->setParameters([
            'start_date' => $startDate,
            'min_total' => $minTotal,
        ], [
            'min_total' => 'Float64',
        ])
        ->get();

    return $stats;
}

// Example 10: Checking and clearing parameters
function example10_parameter_management()
{
    $builder = DB::connection('clickhouse')
        ->table('users')
        ->bind('user_id', 42, 'UInt32')
        ->bind('status', 'active');

    // Check if parameter exists
    if ($builder->hasParameter('user_id')) {
        echo "Parameter 'user_id' is set\n";
    }

    // Get parameter value
    $param = $builder->getParameter('user_id');
    echo "User ID: " . $param->getValue() . "\n";

    // Get all parameters
    $allParams = $builder->getParameters();
    echo "Total parameters: " . count($allParams) . "\n";

    // Get HTTP formatted parameters
    $httpParams = $builder->getHttpParameters();
    print_r($httpParams);

    // Clear all parameters
    $builder->clearParameters();
    echo "Parameters cleared: " . count($builder->getParameters()) . "\n";
}
