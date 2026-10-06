<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

$signatureHelp = fn (
    string $file,
    string $marker,
    Expectation\SignatureHelpExpectationInterface ...$expect,
): Session\Script => new Session\Script(
    project: 'tests/Fixtures',
    steps: [
        new Step\Open($file),
        new Step\SignatureHelp($file, new Marker\CursorMarker($marker), expect: array_values($expect)),
    ],
);

return [
    'new' => $signatureHelp(
        'SignatureHelp.php',
        'constructor',
        new Expectation\SignatureShows('__construct', 'string $id'),
    ),
    'attribute' => $signatureHelp(
        'src/Completion/AttributeNamedArguments.php',
        'attr_arg_empty',
        new Expectation\SignatureShows('string $path'),
        new Expectation\ActiveParameter(0),
    ),
    // `self` in `new self(...)` is the enclosing class.
    'new self inside a class' => $signatureHelp(
        'src/LateBinding/PrivateCtor.php',
        'sig_new_self_inside',
        new Expectation\SignatureShows('string $label'),
    ),
    // A private constructor is still shown, so its parameters can be read.
    'private constructor' => $signatureHelp(
        'src/LateBinding/PrivateCtorCaller.php',
        'sig_new_private_ctor',
        new Expectation\SignatureShows('string $label'),
    ),
    // `parent::` is the parent class, not the enclosing one (#101). ChildClass has
    // no constructor, so `$name` can only come from ParentClass.
    'parent constructor' => $signatureHelp(
        'src/Inheritance/ChildClass.php',
        'parent_sig',
        new Expectation\SignatureShows('__construct', '$name'),
    ),
];
