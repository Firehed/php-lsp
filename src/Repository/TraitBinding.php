<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Repository;

/**
 * When a used trait's members are bound into a class, relative to the class
 * inheriting from its parent (`zend_do_link_class` in php-src).
 */
enum TraitBinding
{
    case BeforeInheritance;
    case AfterInheritance;
}
