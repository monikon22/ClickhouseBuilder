<?php

namespace Tinderbox\ClickhouseBuilder\Query;

/**
 * Class Parameter
 *
 * Represents a query parameter for prepared statements in ClickHouse.
 * Parameters are placeholders in queries that can be replaced with actual values
 * in a type-safe manner.
 *
 * @package Tinderbox\ClickhouseBuilder\Query
 */
class Parameter
{
    /**
     * Parameter name (without curly braces).
     *
     * @var string
     */
    protected $name;

    /**
     * Parameter value.
     *
     * @var mixed
     */
    protected $value;

    /**
     * Parameter type (ClickHouse type: String, UInt8, Int32, etc.).
     *
     * @var string|null
     */
    protected $type;

    /**
     * Parameter constructor.
     *
     * @param string      $name  Parameter name
     * @param mixed       $value Parameter value
     * @param string|null $type  ClickHouse type
     */
    public function __construct(string $name, $value, string $type = null)
    {
        $this->name = $name;
        $this->value = $value;
        $this->type = $type ?? $this->inferType($value);
    }

    /**
     * Get parameter name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get parameter value.
     *
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * Get parameter type.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Set parameter value.
     *
     * @param mixed $value
     *
     * @return self
     */
    public function setValue($value): self
    {
        $this->value = $value;

        return $this;
    }

    /**
     * Set parameter type.
     *
     * @param string $type
     *
     * @return self
     */
    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Get parameter placeholder for use in query.
     * Returns format: {name:Type}
     *
     * @return string
     */
    public function getPlaceholder(): string
    {
        return "{{$this->name}:{$this->type}}";
    }

    /**
     * Get parameter in format for HTTP request.
     * Returns format: param_name=value
     *
     * @return array ['param_name' => 'value']
     */
    public function toHttpParam(): array
    {
        return ["param_{$this->name}" => $this->formatValue()];
    }

    /**
     * Format value for HTTP request or bindings.
     *
     * @return string|int|float
     */
    public function formatValue()
    {
        if (is_bool($this->value)) {
            return $this->value ? '1' : '0';
        }

        if (is_array($this->value)) {
            return '[' . implode(',', array_map(function ($v) {
                return is_string($v) ? "'" . addslashes($v) . "'" : $v;
            }, $this->value)) . ']';
        }

        return $this->value;
    }

    /**
     * Infer ClickHouse type from PHP value.
     *
     * @param mixed $value
     *
     * @return string
     */
    protected function inferType($value): string
    {
        if (is_int($value)) {
            if ($value >= 0) {
                if ($value <= 255) {
                    return 'UInt8';
                } elseif ($value <= 65535) {
                    return 'UInt16';
                } elseif ($value <= 4294967295) {
                    return 'UInt32';
                } else {
                    return 'UInt64';
                }
            } else {
                if ($value >= -128 && $value <= 127) {
                    return 'Int8';
                } elseif ($value >= -32768 && $value <= 32767) {
                    return 'Int16';
                } elseif ($value >= -2147483648 && $value <= 2147483647) {
                    return 'Int32';
                } else {
                    return 'Int64';
                }
            }
        } elseif (is_float($value)) {
            return 'Float64';
        } elseif (is_bool($value)) {
            return 'UInt8';
        } elseif (is_string($value)) {
            return 'String';
        } elseif (is_array($value)) {
            // Infer array type from first element
            if (!empty($value)) {
                $firstType = $this->inferType(reset($value));
                return "Array({$firstType})";
            }
            return 'Array(String)';
        }

        return 'String';
    }

    /**
     * Create parameter instance from placeholder string.
     * Example: {id:UInt32} -> Parameter('id', null, 'UInt32')
     *
     * @param string $placeholder
     *
     * @return self|null
     */
    public static function fromPlaceholder(string $placeholder): ?self
    {
        if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*):([a-zA-Z0-9()]+)\}$/', $placeholder, $matches)) {
            return new self($matches[1], null, $matches[2]);
        }

        return null;
    }

    /**
     * Convert to string (returns placeholder).
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->getPlaceholder();
    }
}
