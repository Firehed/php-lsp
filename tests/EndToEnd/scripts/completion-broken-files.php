<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

// Completion in files whose text does not parse, opened that way.
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
$aliased = 'src/IncompleteCode/AliasedImports.php';
$brokenClass = 'src/IncompleteCode/BrokenClassMembers.php';
$brokenInheritance = 'src/IncompleteCode/BrokenInheritance.php';
$chained = 'src/IncompleteCode/ChainedAccess.php';
$group = 'src/IncompleteCode/GroupImports.php';
$inControl = 'src/IncompleteCode/InControlStructures.php';
$parentAccess = 'src/IncompleteCode/ParentAccess.php';
$staticAccess = 'TopLevel/static_access.php';
$thisAccess = 'TopLevel/this_access.php';

$userMembers = new Expectation\Offers('getName', 'manager');
$userStatics = new Expectation\Offers(
    'DEFAULT_ROLE',
    'class',
    'create',
    'getInstanceCount',
    'triggerDefNullsafeViaAssignment',
    'triggerDefViaAssignment',
);
$nothing = new Expectation\NoAnswer();
$seed = 'TopLevel/very_broken_seed.php';

return [
    'an aliased import as a parameter type' => $complete($aliased, 'aliased_param', $userMembers),
    'static access on an aliased import' => $complete($aliased, 'aliased_static_access', $userStatics),
    'arguments to new on a name imported as both a class and a function' => $complete(
        $aliased,
        'colliding_new',
        new Expectation\Offers('Widget', 'AliasedUser'),
    ),
    'static access on a name imported as both a class and a function' => $complete(
        $aliased,
        'colliding_static',
        new Expectation\Offers('class'),
    ),
    'instance members of a broken class' => $complete(
        $brokenClass,
        'broken_instance',
        new Expectation\Offers('name', 'test'),
    ),
    'static members of a broken class' => $complete(
        $brokenClass,
        'broken_static',
        new Expectation\Offers('NAME', 'class', 'create'),
    ),
    'inherited instance members of a broken class' => $complete(
        $brokenInheritance,
        'broken_inherited',
        new Expectation\Offers(
            '__construct',
            'childMethod',
            'grandparentMethod',
            'grandparentProperty',
            'overriddenMethod',
            'parentMethod',
            'parentProperty',
            'protectedGrandparentMethod',
            'protectedMethod',
            'protectedProperty',
            'sharedProperty',
            'testInstance',
            'testStatic',
            'triggerDefPrivateMethod',
            'triggerDefProtectedMethod',
            'triggerHoverClassConstant',
            'triggerHoverStaticProperty',
        ),
    ),
    'inherited static members of a broken class' => $complete(
        $brokenInheritance,
        'broken_static_inherited',
        new Expectation\Offers(
            'CHILD_CONST',
            'GRANDPARENT_CONST',
            'PARENT_CONST',
            'PROTECTED_CONST',
            'class',
            'grandparentStaticProtected',
            'grandparentStaticPublic',
            'protectedStaticMethod',
            'staticMethod',
            'staticProperty',
        ),
    ),
    'a doubled arrow' => $complete($chained, 'double_arrow', $nothing),
    'a chain through a member that does not exist' => $complete($chained, 'nonexistent_chain', $nothing),
    'a chain on an untyped value' => $complete($chained, 'untyped_chain', $nothing),
    'static access on an aliased group import' => $complete(
        $group,
        'group_aliased_static',
        new Expectation\Offers('Critical', 'High', 'Low', 'Medium', 'cases', 'class', 'from', 'fromScore', 'tryFrom'),
    ),
    'static access on a group import' => $complete($group, 'group_static_access', $userStatics),
    'a group import as a parameter type' => $complete($group, 'group_user_param', $userMembers),
    'a nullable parameter inside a control structure' => $complete($inControl, 'nullable_param', $userMembers),
    'a primitive parameter' => $complete($inControl, 'primitive_param', $nothing),
    'a $this prefix inside an if' => $complete(
        $inControl,
        'this_prefix_if',
        new Expectation\Offers('getName', 'getUser'),
    ),
    'a variable inside a while' => $complete($inControl, 'var_access_while', $userMembers),
    'parent:: in a broken class' => $complete(
        $parentAccess,
        'parent_incomplete',
        new Expectation\Offers(
            '__construct',
            'grandparentMethod',
            'grandparentStaticProtected',
            'grandparentStaticPublic',
            'overriddenMethod',
            'parentMethod',
            'protectedGrandparentMethod',
            'protectedMethod',
            'protectedStaticMethod',
            'staticMethod',
            'triggerDefPrivateMethod',
            'triggerDefProtectedMethod',
            'triggerHoverClassConstant',
            'triggerHoverStaticProperty',
        ),
    ),
    'parent:: in a class that extends nothing' => $complete($parentAccess, 'parent_no_extends', $nothing),
    'a broken self:: outside a class' => $complete('TopLevel/broken_self.php', 'broken_self_toplevel', $nothing),
    'member access in a namespaced global scope' => $complete(
        'TopLevel/global_scope_completion_ns.php',
        'global_member_access_ns',
        $userMembers,
    ),
    'a nested position in global scope' => $complete('TopLevel/global_scope_nested.php', 'nested_marker', $nothing),
    'static access in a file with no tree' => $complete(
        'TopLevel/no_ast.php',
        'empty_ast_static',
        new Expectation\Offers('class'),
    ),
    'static access inside an anonymous class' => $complete($staticAccess, 'anon_class_static', $userStatics),
    'self:: outside a class' => $complete($staticAccess, 'toplevel_self', $nothing),
    'static:: outside a class' => $complete($staticAccess, 'toplevel_static', $nothing),
    'a chain on $this outside a class' => $complete($thisAccess, 'this_chained_toplevel', $nothing),
    '$this outside a class' => $complete($thisAccess, 'this_toplevel', $nothing),
    'a parsing file changed to broken text' => new Session\Script(
        project: 'tests/Fixtures',
        steps: [
            new Step\Open($seed),
            new Step\Replace($seed, 'src/IncompleteCode/VeryBroken.php'),
            new Step\Complete(
                $seed,
                new Marker\CursorMarker('this_in_if'),
                expect: [new Expectation\Offers('getName', 'name', 'test')],
            ),
        ],
    ),
];
