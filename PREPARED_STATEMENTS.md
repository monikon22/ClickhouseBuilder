# Prepared Statements Support for ClickHouse Builder

This document describes the prepared statements (parametrized queries) support added to the ClickHouse Builder library for Laravel.

## Overview

Prepared statements provide a secure and efficient way to execute queries with dynamic values. According to [ClickHouse HTTP Interface documentation](https://clickhouse.com/docs/interfaces/http#cli-queries-with-parameters), parameters are specified using curly braces with the parameter name and type: `{name:Type}`, and values are passed via HTTP parameters `param_name=value`.

## Features

- **Type-safe parameters**: Automatic type inference for PHP values to ClickHouse types
- **Easy binding**: Simple methods to bind values to parameters
- **Security**: Protection against SQL injection
- **Flexibility**: Support for all ClickHouse data types including arrays

## Usage

### Basic Parameter Binding

```php
use Tinderbox\ClickhouseBuilder\Query\Parameter;

// Method 1: Using Parameter instance (explicit)
$builder = DB::connection('clickhouse')
    ->table('users')
    ->select('*')
    ->where('id', '=', new Parameter('user_id', 42, 'UInt32'))
    ->bindValue('user_id', 42, 'UInt32');

$result = $builder->get();
```

### Auto-Converting Values (Simplified Approach)

The library supports automatic conversion of plain PHP values into Parameters without explicitly creating Parameter instances:

```php
// Method 2: Using plain values (auto-conversion with type inference)
$builder = DB::connection('clickhouse')
    ->table('users')
    ->select('*')
    ->where('id', '=', 42)  // Automatically converted to Parameter with UInt8 type
    ->bind('id', 42);  // Optional: explicitly bind the value

$result = $builder->get();
```

The library automatically:

1. **Infers the parameter name** from the column name when needed
2. **Detects the appropriate ClickHouse type** based on the PHP value type
3. **Creates Parameter instances** internally without requiring explicit instantiation

This approach is much simpler for basic queries while maintaining type safety and SQL injection protection.

### Using the bind() Method

```php
$builder = DB::connection('clickhouse')
    ->table('products')
    ->select('name', 'price')
    ->where('price', '>', new Parameter('min_price', 100.00, 'Float64'))
    ->bind('min_price', 100.00, 'Float64');

$result = $builder->get();
```

### Setting Multiple Parameters

```php
$builder = DB::connection('clickhouse')
    ->table('orders')
    ->select('*')
    ->where('total', '>=', new Parameter('min_total', 100))
    ->where('status', '=', new Parameter('status', 'completed'))
    ->setParameters([
        'min_total' => 100,
        'status' => 'completed',
    ], [
        'min_total' => 'Float64',  // Optional: specify types
        'status' => 'String',
    ]);

$result = $builder->get();
```

### Automatic Type Inference

The library automatically infers ClickHouse types from PHP values:

```php
$param1 = new Parameter('id', 123);           // Infers UInt8
$param2 = new Parameter('price', 19.99);      // Infers Float64
$param3 = new Parameter('name', 'John');      // Infers String
$param4 = new Parameter('active', true);      // Infers UInt8
$param5 = new Parameter('ids', [1, 2, 3]);    // Infers Array(UInt8)
```

### Complex Queries

```php
$builder = DB::connection('clickhouse')
    ->table('events')
    ->select('event_id', 'user_id', 'created_at')
    ->where('user_id', '=', new Parameter('uid', 12345, 'UInt32'))
    ->where('created_at', '>=', new Parameter('start_date', '2024-01-01', 'String'))
    ->where('created_at', '<=', new Parameter('end_date', '2024-12-31', 'String'))
    ->where('status', 'IN', new Parameter('statuses', ['active', 'pending'], 'Array(String)'))
    ->setParameters([
        'uid' => 12345,
        'start_date' => '2024-01-01',
        'end_date' => '2024-12-31',
        'statuses' => ['active', 'pending'],
    ], [
        'uid' => 'UInt32',
        'statuses' => 'Array(String)',
    ]);

$results = $builder->get();
```

## Type Mapping

The library supports automatic type mapping from PHP to ClickHouse types:

| PHP Type                                 | ClickHouse Type |
| ---------------------------------------- | --------------- |
| int (0-255)                              | UInt8           |
| int (0-65535)                            | UInt16          |
| int (0-4294967295)                       | UInt32          |
| int (larger)                             | UInt64          |
| negative int (-128 to 127)               | Int8            |
| negative int (-32768 to 32767)           | Int16           |
| negative int (-2147483648 to 2147483647) | Int32           |
| negative int (larger)                    | Int64           |
| float                                    | Float64         |
| string                                   | String          |
| bool                                     | UInt8           |
| array                                    | Array(Type)     |

### Examples of Automatic Type Inference

```php
// No need to specify Parameter class or explicit types - the library infers them:

$builder->where('id', '=', 42);                    // Auto: UInt8
$builder->where('count', '>', 1000);               // Auto: UInt16
$builder->where('price', '>=', 99.99);             // Auto: Float64
$builder->where('name', '=', 'John');              // Auto: String
$builder->where('active', '=', true);              // Auto: UInt8
$builder->where('tags', 'IN', ['tag1', 'tag2']); // Auto: Array(String)
```

## Parameter Class

### Creating Parameters

```php
use Tinderbox\ClickhouseBuilder\Query\Parameter;

// With explicit type
$param = new Parameter('user_id', 42, 'UInt32');

// With automatic type inference
$param = new Parameter('name', 'Alice');
```

### Parameter Methods

```php
$param = new Parameter('id', 100, 'UInt32');

// Get parameter name
$name = $param->getName();  // 'id'

// Get parameter value
$value = $param->getValue();  // 100

// Get parameter type
$type = $param->getType();  // 'UInt32'

// Get placeholder for query
$placeholder = $param->getPlaceholder();  // '{id:UInt32}'

// Get HTTP parameter format
$httpParam = $param->toHttpParam();  // ['param_id' => 100]

// Update value
$param->setValue(200);

// Update type
$param->setType('UInt64');
```

### Parsing Placeholders

```php
// Create parameter from placeholder string
$param = Parameter::fromPlaceholder('{id:UInt32}');

echo $param->getName();  // 'id'
echo $param->getType();  // 'UInt32'
```

## Builder Methods

### bindValue($name, $value, $type = null)

Binds a value to a parameter.

```php
$builder->bindValue('user_id', 42, 'UInt32');
```

### bind($name, $value, $type = null)

Alias for `bindValue()`.

```php
$builder->bind('status', 'active');
```

### setParameters($parameters, $types = [])

Sets multiple parameters at once.

```php
$builder->setParameters([
    'min_age' => 18,
    'max_age' => 65,
    'country' => 'USA',
], [
    'min_age' => 'UInt8',
    'max_age' => 'UInt8',
]);
```

### getParameters()

Returns all bound parameters.

```php
$params = $builder->getParameters();  // Returns Parameter[]
```

### getParameter($name)

Returns a specific parameter by name.

```php
$param = $builder->getParameter('user_id');  // Returns Parameter|null
```

### hasParameter($name)

Checks if a parameter exists.

```php
if ($builder->hasParameter('user_id')) {
    // Parameter exists
}
```

### clearParameters()

Clears all bound parameters.

```php
$builder->clearParameters();
```

### getHttpParameters()

Returns parameters formatted for HTTP request.

```php
$httpParams = $builder->getHttpParameters();
// Returns: ['param_user_id' => 42, 'param_status' => 'active']
```

## Security Benefits

Prepared statements provide protection against SQL injection:

```php
// UNSAFE: Direct value interpolation
$builder->whereRaw("user_id = $userId");  // ❌ Vulnerable to SQL injection

// SAFE: Using prepared statements
$builder->where('user_id', '=', new Parameter('uid', $userId, 'UInt32'))
    ->bind('uid', $userId, 'UInt32');  // ✅ Safe from SQL injection
```

## Performance

Prepared statements can improve performance for repeated queries with different parameters, as ClickHouse can optimize and cache the query plan.

## Testing

The library includes comprehensive tests for prepared statements:

```bash
./vendor/bin/phpunit tests/PreparedStatementsTest.php
```

All 24 tests covering various scenarios pass successfully.

## Examples

### Example 1: Simple SELECT with Parameter

```php
$users = DB::connection('clickhouse')
    ->table('users')
    ->select('id', 'name', 'email')
    ->where('age', '>', new Parameter('min_age', 18, 'UInt8'))
    ->bind('min_age', 18, 'UInt8')
    ->get();
```

### Example 2: INSERT with Parameters

```php
DB::connection('clickhouse')
    ->table('logs')
    ->insert([
        'user_id' => new Parameter('uid', 12345, 'UInt32'),
        'action' => new Parameter('action', 'login', 'String'),
        'timestamp' => new Parameter('ts', time(), 'UInt32'),
    ])
    ->setParameters([
        'uid' => 12345,
        'action' => 'login',
        'ts' => time(),
    ], [
        'uid' => 'UInt32',
        'ts' => 'UInt32',
    ])
    ->execute();
```

### Example 3: Complex Filtering

```php
$orders = DB::connection('clickhouse')
    ->table('orders')
    ->select('order_id', 'customer_name', 'total', 'status')
    ->where('total', '>=', new Parameter('min_total', 100.00))
    ->where('total', '<=', new Parameter('max_total', 1000.00))
    ->where('status', 'IN', new Parameter('statuses', ['pending', 'processing']))
    ->where('created_at', '>=', new Parameter('start_date', '2024-01-01'))
    ->setParameters([
        'min_total' => 100.00,
        'max_total' => 1000.00,
        'statuses' => ['pending', 'processing'],
        'start_date' => '2024-01-01',
    ], [
        'min_total' => 'Float64',
        'max_total' => 'Float64',
        'statuses' => 'Array(String)',
    ])
    ->get();
```

## Laravel Connection: Laravel Placeholder Auto-Conversion

When using the Laravel integration, the Connection class automatically converts Laravel-style placeholders (`?`) to ClickHouse parameter format (`{pN:Type}`). This allows you to write queries using familiar Laravel syntax while maintaining SQL injection protection.

> **Note:** Laravel-style placeholder auto-conversion is only supported through the Connection helper methods (`insert()`, `select()`, `statement()`, `delete()`) and the Query Builder. Direct raw SQL execution outside of these methods will not have placeholders converted.

### Basic Usage with Laravel Syntax

```php
// Using Laravel-style placeholders - automatically converted to ClickHouse parameters
$connection->insert('INSERT INTO users (id, name, email) VALUES (?, ?, ?)', [1, 'John', 'john@example.com']);

// Using statement() method
$connection->statement('CREATE TABLE IF NOT EXISTS logs (id UInt32, message String) Engine = Memory');

// Using select() method with placeholders
$result = $connection->select('SELECT * FROM users WHERE id = ? AND status = ?', [42, 'active']);
```

### How It Works

1. **Detection**: The library automatically detects Laravel-style `?` placeholders
2. **Type Inference**: It infers the ClickHouse type from the PHP value
3. **Parameter Naming**: Creates sequential parameter names (p0, p1, p2, etc.)
4. **Query Transformation**: Replaces `?` with `{pN:Type}` format
5. **Value Formatting**: Formats values appropriately for ClickHouse

### Automatic Type Detection

```php
// UInt8 - for integers 0-255
$connection->insert('INSERT INTO stats (count) VALUES (?)', [100]);

// Float64 - for floating point numbers
$connection->insert('INSERT INTO metrics (value) VALUES (?)', [3.14]);

// String - for text
$connection->insert('INSERT INTO logs (message) VALUES (?)', ['User login']);

// UInt8 (true/false) - for booleans
$connection->insert('INSERT INTO flags (enabled) VALUES (?)', [true]);

// Nullable(String) - for null values
$connection->insert('INSERT INTO optional (data) VALUES (?)', [null]);
```

### Builder Integration

The Builder classes automatically use prepared statements and pass parameters correctly:

```php
// Query Builder with where clauses
$connection->table('users')
    ->where('id', '=', 42)
    ->where('status', '=', 'active')
    ->delete();  // Automatically uses prepared statements

// With multiple conditions
$results = $connection->table('orders')
    ->where('amount', '>', 100.00)
    ->where('created_at', '>=', '2024-01-01')
    ->select('*')
    ->get();
```

### String Escaping

The library automatically handles string escaping to prevent SQL injection:

```php
// Strings with quotes are properly escaped
$connection->insert(
    "INSERT INTO comments (text) VALUES (?)",
    ["O'Reilly's book"]  // Automatically escaped
);

// Proper SQL injection prevention
$connection->select(
    "SELECT * FROM users WHERE name = ?",
    ["'; DROP TABLE users; --"]  // Treated as plain string, not SQL
);
```

### Mixed Usage

You can mix prepared statements (from Builder) with Laravel placeholders (from Connection.insert/statement):

```php
// Direct connection method with placeholders
$connection->insert('INSERT INTO logs (user_id, message) VALUES (?, ?)', [123, 'Login']);

// Builder method with parameters
$connection->table('users')
    ->where('status', '=', 'pending')
    ->delete();

// Both use prepared statements internally
```

## Troubleshooting

### Missing Parameter Error: "Substitution `pN` is not set"

If you encounter this error, it means the SQL query contains parameter placeholders (e.g., `{p0:String}`) but the parameters are not being passed to ClickHouse.

**Common Causes:**

1. **Multiple WHERE clauses with expressions** - When using functions like `LOWER()` or `UPPER()` in WHERE clauses, ensure all parameters are properly transmitted through the Builder → Connection chain.

2. **Incorrect parameter format** - If bindings array has mixed key types (numeric and string keys together), parameters may not be recognized:
   ```php
   // CORRECT - all string keys matching p\d+ pattern
   $bindings = ['p0' => 'value0', 'p1' => 'value1'];
   ```

3. **Direct raw SQL without Connection methods** - Parameters are only supported through the Builder interface and Connection helper methods (`insert()`, `select()`, `statement()`). Raw SQL executed through other means will not have placeholder conversion.

**Debugging:**

Add logging to see what parameters are being passed:

```php
// Check what Builder generates
$builder = $connection->table('users')
    ->where('server', '=', 'US')
    ->where($connection->raw('LOWER(player)'), 'LIKE', 'j%');

var_dump($builder->toSql());        // SQL with {pN:Type} placeholders
var_dump($builder->getBindings());  // Should have ['p0' => 'US', 'p1' => 'j%']

// Then execute
$result = $builder->get();
```

## Limitations

- Parameters can only be used in specific SQL contexts (WHERE, HAVING, etc.)
- Array parameters must use proper ClickHouse array syntax
- The HTTP interface must support query parameters (available in ClickHouse by default)
- Laravel placeholders (`?`) are converted on each query execution

## References

- [ClickHouse HTTP Interface Documentation](https://clickhouse.com/docs/interfaces/http#cli-queries-with-parameters)
- [ClickHouse Data Types](https://clickhouse.com/docs/sql-reference/data-types/)
- [SQL Injection Prevention](https://cheatsheetseries.owasp.org/cheatsheets/SQL_Injection_Prevention_Cheat_Sheet.html)

## Contributing

If you find any issues or have suggestions for improvements, please open an issue or submit a pull request on GitHub.

## License

This feature is part of the ClickHouse Builder library and follows the same license as the main project.
