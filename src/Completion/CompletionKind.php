<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Completion;

/**
 * The kind of completion appropriate at a cursor position, as determined by
 * text-based analysis of the code before the cursor.
 *
 * Detection is intentionally text-based so completion keeps working on
 * temporarily-broken, mid-edit code where the parser cannot produce a usable
 * AST. Member, static, and call-argument contexts are detected separately via
 * {@see \Firehed\PhpLsp\Resolution\CodeResolver} and are not represented here.
 */
enum CompletionKind
{
    /** After `$`, e.g. `$fo` — variables in scope */
    case Variable;

    /** After `new`, e.g. `new Fo` — instantiable class names */
    case New_;

    /** After a visibility keyword, e.g. `private fo` — member keywords or a property type */
    case AfterVisibility;

    /** In a return type position, e.g. `): Fo` or `): ?Fo` */
    case ReturnType;

    /** In a property type position, e.g. `private ?Fo` */
    case PropertyType;

    /** In a parameter type position, e.g. `(Fo` or `int|Fo` */
    case ParameterType;

    /** In an `implements` list, e.g. `class Foo implements Ba` — interfaces only */
    case InterfaceList;

    /** After `class X extends`, e.g. `class Foo extends Ba` — extendable classes only */
    case ExtendableClass;

    /** In an attribute position, e.g. `#[Ba` — attribute classes only */
    case Attribute;

    /** Directly inside a class body — class-level keywords only */
    case ClassBody;

    /** At the start of an expression — keywords, functions, and class names */
    case Expression;

    /** No completion applies at this position */
    case None;
}
