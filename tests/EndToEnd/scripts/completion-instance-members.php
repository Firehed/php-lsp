<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$methodAccess = 'src/Completion/MethodAccess.php';
$procedural = 'src/Mixed/ProceduralWithClass.php';
$multiClass = 'MultiClass/MultiClass.php';
$chains = 'src/Completion/ChainCompletion.php';
$staticAccess = 'src/Completion/StaticAccess.php';

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

return [
    '$this properties' => $complete($methodAccess, 'this_empty', new Expectation\Offers('name', 'count', 'active')),
    'parameter of the same class shows every visibility' => $complete(
        $methodAccess,
        'param_access',
        new Expectation\Offers('getName', 'setName', 'active', 'secretMethod', 'hiddenMethod'),
    ),
    'variable from new' => $complete($methodAccess, 'var_empty', new Expectation\Offers('getName', 'setName')),
    'variable with a prefix' => $complete(
        $methodAccess,
        'var_prefix',
        new Expectation\Offers('getName', 'getCount'),
        new Expectation\Withholds('setName'),
    ),
    'nullsafe $this' => $complete($methodAccess, 'nullsafe_this_empty', new Expectation\Offers('getName', 'setName')),
    'nullsafe variable' => $complete($methodAccess, 'nullsafe_var_empty', new Expectation\Offers('getName')),
    'nullsafe $this with a prefix' => $complete(
        $methodAccess,
        'nullsafe_this_prefix',
        new Expectation\Offers('getName', 'getCount'),
        new Expectation\Withholds('setName'),
    ),
    'file-level variable' => $complete(
        'TopLevel/global_scope_completion.php',
        'global_member_access',
        new Expectation\Offers('getName'),
    ),
    'from another class, public members only' => $complete(
        'src/Completion/ExternalAccess.php',
        'external_method_access',
        new Expectation\Offers('active', 'getName', 'getCount'),
        new Expectation\Withholds('name', 'count', 'secretMethod', 'hiddenMethod'),
    ),
    'interface members include extended interfaces' => $complete(
        'src/Completion/PsrRequestAccess.php',
        'psr_request_access',
        new Expectation\Offers('getMethod', 'getHeaders', 'getProtocolVersion'),
    ),
    'variable from a static call returning self' => $complete(
        $procedural,
        'var_from_static_call',
        new Expectation\Offers('triggerSelfEmpty'),
        new Expectation\Withholds('create'),
    ),
    'nullsafe variable from a static call returning self' => $complete(
        $procedural,
        'var_from_static_call_nullsafe',
        new Expectation\Offers('triggerSelfEmpty'),
        new Expectation\Withholds('create'),
    ),
    'built-in class members' => $complete(
        $procedural,
        'array_object_access',
        new Expectation\Offers('append', 'count', 'getIterator'),
    ),
    'from a function, public members only' => $complete(
        $procedural,
        'standalone_function_access',
        new Expectation\Offers('active', 'getName'),
        new Expectation\Withholds('name', 'secretMethod'),
    ),
    'typed function parameter' => $complete($procedural, 'user_param_access', new Expectation\Offers('getName')),
    'unknown type' => $complete($procedural, 'unknown_var', new Expectation\NoAnswer()),
    'dynamic variable name' => $complete($procedural, 'dynamic_var', new Expectation\NoAnswer()),
    '$this outside a class' => $complete($procedural, 'this_outside_class', new Expectation\NoAnswer()),
    '$this in an anonymous class' => $complete(
        'AnonymousClass.php',
        'this_in_anonymous',
        new Expectation\NoAnswer(),
    ),
    '$this in a class without a namespace' => $complete(
        'NoNamespace.php',
        'this_no_namespace',
        new Expectation\Offers('methodWithThis'),
    ),
    '$this in the second class of a file' => $complete(
        $multiClass,
        'this_in_second_class',
        new Expectation\Offers(
            'ownProperty',
            'ownMethod',
            'triggerThisInChild',
            'inheritedProperty',
            'inheritedMethod',
        ),
    ),
    '$this ignores an unrelated class in the same file' => $complete(
        $multiClass,
        'this_in_unrelated_second',
        new Expectation\Offers('secondProperty', 'secondMethod', 'triggerThisInSecond'),
        new Expectation\Withholds('firstProperty', 'firstMethod'),
    ),
    'property chain' => $complete($chains, 'property_chain', new Expectation\Offers('getName')),
    'method chain' => $complete($chains, 'method_chain', new Expectation\Offers('getName')),
    'multi-level chain' => $complete($chains, 'multi_level_chain', new Expectation\Offers('toUpper')),
    'chain from a static method' => $complete($chains, 'static_method_chain', new Expectation\Offers('build')),
    'chain across lines' => $complete($chains, 'multi_line_chain', new Expectation\Offers('toUpper')),
    'nullsafe property chain' => $complete($chains, 'nullsafe_property_chain', new Expectation\Offers('getName')),
    'mixed nullsafe chain' => $complete($chains, 'mixed_nullsafe_chain', new Expectation\Offers('getName')),
    'chain on a primitive' => $complete($chains, 'primitive_chain', new Expectation\NoAnswer()),
    'chain through a method returning a primitive' => $complete(
        $chains,
        'method_call_after_primitive',
        new Expectation\NoAnswer(),
    ),
    'chain through a dynamic method' => $complete($chains, 'dynamic_method_chain', new Expectation\NoAnswer()),
    'chain from a function' => $complete(
        'FunctionCompletion.php',
        'function_return_chain',
        new Expectation\Offers('get'),
    ),
    'chain from a namespaced function' => $complete(
        'src/Completion/FunctionCompletion.php',
        'function_return_chain',
        new Expectation\Offers('get'),
    ),
    'chain after self::' => $complete(
        $staticAccess,
        'self_chain',
        new Expectation\Offers('getInstanceProp', 'instanceProp'),
    ),
    'chain after static::' => $complete(
        $staticAccess,
        'static_chain',
        new Expectation\Offers('getInstanceProp', 'instanceProp'),
    ),
    'chain after self:: with a prefix' => $complete(
        $staticAccess,
        'self_chain_prefix',
        new Expectation\Offers('getInstanceProp'),
        new Expectation\Withholds('instanceProp'),
    ),
    '$this inside an unfinished if' => $complete(
        'src/IncompleteCode/SingleIncomplete.php',
        'this_in_if',
        new Expectation\Offers('getName', 'name'),
    ),
    '$this in a file with no closing braces' => $complete(
        'src/IncompleteCode/VeryBroken.php',
        'this_in_if',
        new Expectation\Offers('getName', 'name'),
    ),
    'chain inside an unfinished if' => $complete(
        'src/IncompleteCode/ChainedAccess.php',
        'chained_in_if',
        new Expectation\Offers('getName'),
    ),
];
