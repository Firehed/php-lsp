<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$noAnswer = fn (string $file, string $marker): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\SignatureHelp($file, new Marker\CursorMarker($marker), expect: new Expectation\NoAnswer()),
    ],
);

$outside = 'EdgeCases/SelfOutsideClass.php';
$parentless = 'EdgeCases/ParentWithoutExtends.php';
$calls = 'SignatureHelp.php';
$user = 'src/Domain/User.php';
$noNamespace = 'NoNamespace/NoUseStatement.php';

return [
    'self method outside a class' => $noAnswer($outside, 'sig_self_method'),
    'new self outside a class' => $noAnswer($outside, 'sig_new_self'),
    'parent method without extends' => $noAnswer($parentless, 'sig_parent_method'),
    'new parent without extends' => $noAnswer($parentless, 'sig_new_parent'),
    'undefined function' => $noAnswer($calls, 'undefined_func'),
    'class without a constructor' => $noAnswer($calls, 'no_constructor'),
    'method named by a variable' => $noAnswer($user, 'sig_dynamic_method'),
    'static method named by a variable' => $noAnswer($user, 'sig_dynamic_static'),
    'function held in a variable' => $noAnswer($user, 'sig_dynamic_func'),
    'new on a class held in a variable' => $noAnswer($user, 'sig_dynamic_new'),
    'static call on a class held in a variable' => $noAnswer($user, 'sig_computed_class'),
    'method on an untyped variable' => $noAnswer($user, 'sig_untyped_method'),
    'method that does not exist' => $noAnswer($user, 'sig_nonexistent_method'),
    'static method that does not exist' => $noAnswer($user, 'sig_nonexistent_static'),
    'unknown class without a namespace' => $noAnswer($noNamespace, 'no_use_no_namespace'),
    'unknown qualified class without a namespace' => $noAnswer($noNamespace, 'multi_part_class'),
    'unknown function after an empty argument' => $noAnswer($noNamespace, 'whitespace_arg'),
    '$this outside a class' => $noAnswer($noNamespace, 'this_outside_class'),
];
