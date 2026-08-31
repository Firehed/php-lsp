<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Domain;

/**
 * The surface PHP synthesizes for every enum: the interfaces it implements,
 * the properties every case exposes, and the static methods every enum offers.
 *
 * Reflection returns these natively; the AST factory cannot see them in the
 * source tree and must inject them. Consumers that render the enum's declared
 * shape (e.g. {@see ClassInfo::format()}) also need to know which parts are
 * synthesized rather than written. Every one of those consumers reads this
 * one authority so the AST-derived and reflection-derived pictures agree, and
 * so a future implicit (say, a new method on backed enums) is a change to one
 * file rather than a fresh drift between them. {@see \Firehed\PhpLsp\Tests\Repository\TypeGraphParityTest}
 * is the oracle: it fails when reflection reports a member this authority did
 * not synthesize.
 */
final class EnumImplicits
{
    /**
     * @return list<ClassName>
     */
    public static function interfaces(bool $isBacked): array
    {
        $interfaces = [TypeFactory::className(\UnitEnum::class)];
        if ($isBacked) {
            $interfaces[] = TypeFactory::className(\BackedEnum::class);
        }

        return $interfaces;
    }

    public static function isImplicitInterface(ClassName $name): bool
    {
        return $name->fqn === \UnitEnum::class || $name->fqn === \BackedEnum::class;
    }

    /**
     * @return array<string, MethodInfo>
     */
    public static function methods(ClassName $enum, ?PrimitiveType $scalarType): array
    {
        $methods = [
            'cases' => new MethodInfo(
                name: new MethodName('cases'),
                visibility: Visibility::Public,
                isStatic: true,
                isAbstract: false,
                isFinal: false,
                parameters: [],
                returnType: TypeFactory::primitive('array'),
                docblock: null,
                file: null,
                line: null,
                declaringClass: $enum,
            ),
        ];
        if ($scalarType === null) {
            return $methods;
        }
        $valueParam = new ParameterInfo(
            name: 'value',
            type: $scalarType,
            hasDefault: false,
            defaultValue: null,
            position: 0,
            isVariadic: false,
            isPassedByReference: false,
        );
        $methods['from'] = new MethodInfo(
            name: new MethodName('from'),
            visibility: Visibility::Public,
            isStatic: true,
            isAbstract: false,
            isFinal: false,
            parameters: [$valueParam],
            returnType: $enum,
            docblock: null,
            file: null,
            line: null,
            declaringClass: $enum,
        );
        $methods['tryFrom'] = new MethodInfo(
            name: new MethodName('tryFrom'),
            visibility: Visibility::Public,
            isStatic: true,
            isAbstract: false,
            isFinal: false,
            parameters: [$valueParam],
            returnType: TypeFactory::union([$enum, TypeFactory::primitive('null')]),
            docblock: null,
            file: null,
            line: null,
            declaringClass: $enum,
        );

        return $methods;
    }

    /**
     * @return array<string, PropertyInfo>
     */
    public static function properties(ClassName $enum, ?PrimitiveType $scalarType): array
    {
        $properties = [
            'name' => new PropertyInfo(
                name: new PropertyName('name'),
                visibility: Visibility::Public,
                isStatic: false,
                isReadonly: true,
                isPromoted: false,
                type: TypeFactory::primitive('string'),
                docblock: null,
                file: null,
                line: null,
                declaringClass: $enum,
            ),
        ];
        if ($scalarType !== null) {
            $properties['value'] = new PropertyInfo(
                name: new PropertyName('value'),
                visibility: Visibility::Public,
                isStatic: false,
                isReadonly: true,
                isPromoted: false,
                type: $scalarType,
                docblock: null,
                file: null,
                line: null,
                declaringClass: $enum,
            );
        }

        return $properties;
    }
}
