<?php

namespace Fixtures\Legacy;

/**
 * Example of pre-PHP 7 style code with only docblock types.
 */
class DocblockOnly
{
    /** @var string */
    private $name;

    /** @var int */
    private $count;

    /** @var array<string, mixed> */
    private $data;

    /**
     * @param string $name
     * @param int $count
     */
    public function __construct($name, $count = 0)
    {
        $this->name = $name;
        $this->count = $count;
        $this->data = [];
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param string $name
     * @return void
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function setData($key, $value)
    {
        $this->data[$key] = $value;
    }

    /**
     * @param string $key
     * @return mixed|null
     */
    public function getData($key)
    {
        return $this->data[$key] ?? null;
    }
}
