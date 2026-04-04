<?php

namespace Tinderbox\ClickhouseBuilder\Query;

class Expression
{
    /**
     * The value of the expression.
     *
     * @var mixed
     */
    protected $value;

    /**
     * The bindings for the expression.
     *
     * @var array
     */
    protected $bindings = [];

    /**
     * Create a new raw query expression.
     *
     * @param mixed $value
     * @param array $bindings
     */
    public function __construct($value, array $bindings = [])
    {
        $this->value = $value;
        $this->bindings = $bindings;
    }

    /**
     * Get the value of the expression.
     *
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * Get the bindings for the expression.
     *
     * @return array
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Get the value of the expression.
     *
     * @return string
     */
    public function __toString()
    {
        return (string) $this->getValue();
    }
}
