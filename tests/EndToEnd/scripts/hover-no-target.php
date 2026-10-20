<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$noAnswer = fn (string $file, Marker\MarkerInterface $at): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\Hover($file, $at, expect: new Expectation\NoAnswer()),
    ],
);

$outside = 'EdgeCases/SelfOutsideClass.php';
$parentless = 'EdgeCases/ParentWithoutExtends.php';
$user = 'src/Domain/User.php';
$newFallbacks = 'src/Hover/ResolveNewFallbacks.php';
$calls = 'SignatureHelp.php';
$attributes = 'src/Services/ApiController.php';
$at = fn (string $marker): Marker\SymbolMarker => new Marker\SymbolMarker($marker);

return [
    'self method outside a class' => $noAnswer($outside, $at('self_method')),
    'self property outside a class' => $noAnswer($outside, $at('self_property')),
    'parent method without extends' => $noAnswer($parentless, $at('parent_method')),
    'parent property without extends' => $noAnswer($parentless, $at('parent_property')),
    'inside a comment' => $noAnswer('EdgeCases/HoverInComment.php', new Marker\CursorMarker('in_comment')),
    'undefined constant' => $noAnswer($user, $at('undefined_constant')),
    'static property that does not exist' => $noAnswer($user, $at('dynamic_constant')),
    'class constant that does not exist' => $noAnswer($user, $at('unknown_constant')),
    'new on an unknown class' => $noAnswer($user, $at('unknown_class')),
    'a method declaration\'s name' => $noAnswer($user, $at('method_name')),
    'a property declaration' => $noAnswer($user, $at('property_declaration')),
    'method on new of a class held in a variable' => $noAnswer($newFallbacks, $at('variable_new')),
    'method on new of an anonymous class' => $noAnswer($newFallbacks, $at('anon_new')),
    'method on new of an unknown class' => $noAnswer($newFallbacks, $at('unknown_new')),
    'method on new self outside a class' => $noAnswer($newFallbacks, $at('global_self_new')),
    'self constant outside a class' => $noAnswer($calls, $at('self_const_outside')),
    'named argument to an undefined function' => $noAnswer($calls, $at('named_arg_undefined_func')),
    'named argument the function does not take' => $noAnswer($calls, $at('named_arg_wrong_name')),
    'named argument to an attribute without a constructor' => $noAnswer($attributes, $at('attr_no_constructor')),
    'named argument the attribute does not take' => $noAnswer($attributes, $at('attr_wrong_param')),
];
