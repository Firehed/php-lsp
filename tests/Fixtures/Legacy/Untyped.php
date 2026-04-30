<?php

namespace Fixtures\Legacy;

class Untyped
{
    private $value;
    private $items;

    public function __construct($value = null)
    {
        $this->value = $value;
        $this->items = [];
    }

    public function getValue()
    {
        return $this->value;
    }

    public function setValue($value)
    {
        $this->value = $value;
    }

    public function addItem($item)
    {
        $this->items[] = $item;
    }

    public function getItems()
    {
        return $this->items;
    }

    public function process($input)
    {
        if (is_array($input)) {
            return array_map(fn($x) => $x * 2, $input);
        }
        return $input;
    }
}
