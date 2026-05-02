<?php

// Edge cases: dynamic method and class names that cannot be statically resolved.
// All of these should return null for definition lookup.

class DynamicAccessClass
{
    public static function testDynamicStaticMethod(): void
    {
        $method = 'foo';
        self::$method(); //hover:dynamic_static_method
    }

    public function testDynamicInstanceMethod(): void
    {
        $method = 'foo';
        $this->$method(); //hover:dynamic_instance_method
    }
}

$class = 'SomeClass';
$class::method(); //hover:dynamic_class_name
