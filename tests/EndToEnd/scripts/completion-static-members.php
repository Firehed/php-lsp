<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$staticAccess = 'src/Completion/StaticAccess.php';
$inheritance = 'src/Completion/InheritanceCompletion.php';
$child = 'src/Inheritance/ChildClass.php';
$enums = 'src/Completion/EnumUsage.php';
$imports = 'Namespacing/MultiNamespaceImports.php';
$procedural = 'src/Mixed/ProceduralWithClass.php';
$anonymous = 'AnonymousClass.php';

$complete = fn (
    string $file,
    string $marker,
    Expectation\CompletionExpectationInterface ...$expect,
): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Complete($file, new Marker\CursorMarker($marker), expect: array_values($expect)),
    ],
);

$typeThenComplete = fn (
    string $file,
    string $marker,
    string $typed,
    Expectation\CompletionExpectationInterface ...$expect,
): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Type($file, new Marker\CursorMarker($marker), $typed),
        new Step\Complete($file, new Marker\CursorMarker($marker), expect: array_values($expect)),
    ],
);

$relative = 'src/Completion/RelativeQualifiedStatic.php';

return [
    'a relative qualified class' => $typeThenComplete(
        $relative,
        'typing',
        'Sub\Thing::',
        new Expectation\Offers('build'),
    ),
    'a relative qualified class inside a call' => $typeThenComplete(
        $relative,
        'typing',
        'foo(Sub\Thing::',
        new Expectation\Offers('build'),
    ),
    'self:: offers every static member of the class' => $complete(
        $staticAccess,
        'self_empty',
        new Expectation\Offers('create', 'getInstance', 'reset', 'instance', 'counter'),
        new Expectation\Offers('NAME', 'INTERNAL', 'SECRET', 'class'),
        new Expectation\Withholds('triggerSelfEmpty', 'triggerSelfPrefix', 'instanceProp'),
    ),
    'static::' => $complete(
        $staticAccess,
        'static_keyword',
        new Expectation\Offers('NAME', 'INTERNAL', 'SECRET', 'class'),
    ),
    'self:: with a prefix' => $complete(
        $staticAccess,
        'self_const_prefix',
        new Expectation\Offers('NAME'),
        new Expectation\Withholds('INTERNAL', 'SECRET', 'class'),
    ),
    'self:: includes inherited static members' => $complete(
        $inheritance,
        'self_inherited',
        new Expectation\Offers('ownStaticProperty', 'ownStaticMethod'),
        new Expectation\Offers('staticProperty', 'staticMethod', 'PARENT_CONST'),
    ),
    'parent:: offers the parent\'s methods' => $complete(
        $inheritance,
        'parent_access',
        new Expectation\Offers('__construct', 'parentMethod', 'protectedMethod'),
        new Expectation\Offers('staticMethod', 'protectedStaticMethod'),
    ),
    'parent:: with a prefix' => $complete(
        $inheritance,
        'parent_prefix',
        new Expectation\Offers('parentMethod', 'protectedMethod'),
        new Expectation\Withholds('staticMethod', '__construct'),
    ),
    'parent:: without a parent' => $complete(
        'src/Completion/NoParent.php',
        'parent_no_parent',
        new Expectation\NoAnswer(),
    ),
    'an ancestor by name, from a subclass' => $complete(
        $inheritance,
        'parent_class_static',
        new Expectation\Offers('staticMethod', 'protectedStaticMethod'),
        new Expectation\Withholds('privateStaticMethod'),
    ),
    'the direct parent by name' => $complete(
        $child,
        'direct_parent_static',
        new Expectation\Offers('staticMethod', 'protectedStaticMethod'),
        new Expectation\Withholds('privateStaticMethod'),
    ),
    'a grandparent by name' => $complete(
        $child,
        'grandparent_access',
        new Expectation\Offers('grandparentStaticPublic', 'grandparentStaticProtected'),
    ),
    'from a function, public members only' => $complete(
        $procedural,
        'standalone_static_access',
        new Expectation\Offers('create', 'NAME'),
        new Expectation\Withholds('INTERNAL', 'SECRET', 'reset'),
    ),
    'from an anonymous class, public members only' => $complete(
        $anonymous,
        'static_from_anonymous',
        new Expectation\Offers('create'),
        new Expectation\Withholds('reset'),
    ),
    'self:: in an anonymous class' => $complete($anonymous, 'self_in_anonymous', new Expectation\NoAnswer()),
    'self:: outside a class' => $complete($procedural, 'self_outside_class', new Expectation\NoAnswer()),
    'a class held in a variable' => $complete($procedural, 'dynamic_static', new Expectation\NoAnswer()),
    'self:: in the second class of a file' => $complete(
        'MultiClass/MultiClass.php',
        'self_in_second_class',
        new Expectation\Offers('SECOND_CONST'),
        new Expectation\Withholds('FIRST_CONST'),
    ),
    'self:: without a namespace' => $complete(
        'NoNamespace.php',
        'self_no_namespace',
        new Expectation\Offers('staticMethod'),
    ),
    'an imported class' => $complete(
        $imports,
        'imported_static',
        new Expectation\Offers('STATUS_ACTIVE', 'findById', 'class'),
    ),
    'an aliased import' => $complete($imports, 'aliased_static', new Expectation\Offers('ROLE_ADMIN')),
    'unit enum cases' => $complete(
        $enums,
        'unit_enum_empty',
        new Expectation\Offers('Active', 'Inactive', 'Pending', 'class'),
        new Expectation\Details('Active', 'case Active'),
    ),
    'unit enum case prefix' => $complete(
        $enums,
        'unit_enum_prefix',
        new Expectation\Offers('Active'),
        new Expectation\Withholds('Inactive', 'Pending'),
    ),
    'unit enum built-ins' => $complete($enums, 'unit_enum_builtin', new Expectation\Offers('cases', 'class')),
    'int-backed enum' => $complete(
        $enums,
        'backed_int_empty',
        new Expectation\Offers('Low', 'Medium', 'High', 'cases', 'from', 'tryFrom', 'class'),
        new Expectation\Details('Low', 'case Low = 1'),
        new Expectation\Details('from', 'public static function from(int $value): Fixtures\\Enum\\Priority'),
    ),
    'string-backed enum' => $complete(
        $enums,
        'backed_string_empty',
        new Expectation\Offers('Red', 'Green', 'Blue', 'cases', 'from', 'tryFrom'),
        new Expectation\Details('Red', "case Red = 'red'"),
        new Expectation\Details('from', 'public static function from(string $value): Fixtures\\Enum\\Color'),
    ),
    'backed enum prefix' => $complete(
        $enums,
        'backed_int_prefix',
        new Expectation\Offers('from'),
        new Expectation\Withholds('cases', 'tryFrom', 'Low', 'High'),
    ),
];
