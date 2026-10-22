<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution\Position;

/**
 * What the grammar has at a position: what is written there, or what may be.
 */
enum Slot
{
    /** A comment, a string that does not interpolate, or inline HTML. */
    case NotCode;
    /** Text in a string where a variable may be interpolated. */
    case InterpolatedString;
    case Variable;
    /** Where an expression or statement may start; also the fallback. */
    case Expression;
    /** The name of a function being called. */
    case CalledName;
    /** A member name after `->` or `?->`. */
    case Member;
    /** A member name after `::`. */
    case StaticMember;
    /** The class name before `::`. */
    case StaticReceiver;
    /** An argument's name, written with its colon. */
    case ArgumentName;
    /** The name a declaration gives, a parameter's included. */
    case DeclaredName;
    /** Where a class member may start, its modifiers included. */
    case ClassMember;
    case ParameterType;
    case ReturnType;
    case PropertyType;
    case Catch;
    case Instanceof;
    case New;
    case Implements;
    case ClassExtends;
    case InterfaceExtends;
    case Attribute;
    case TraitUse;
    case Import;
}
