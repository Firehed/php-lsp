<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser;

use Firehed\PhpLsp\Domain\Type;
use Firehed\PhpLsp\Domain\TypeFactory;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\DocBlock\Tags\Return_;
use phpDocumentor\Reflection\DocBlock\Tags\Var_;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\DocBlockFactoryInterface;
use phpDocumentor\Reflection\Type as PhpDocType;
use phpDocumentor\Reflection\Types\AggregatedType;
use phpDocumentor\Reflection\Types\Array_;
use phpDocumentor\Reflection\Types\Boolean;
use phpDocumentor\Reflection\Types\Callable_;
use phpDocumentor\Reflection\Types\Compound;
use phpDocumentor\Reflection\Types\Context;
use phpDocumentor\Reflection\Types\Float_;
use phpDocumentor\Reflection\Types\Integer;
use phpDocumentor\Reflection\Types\Intersection;
use phpDocumentor\Reflection\Types\Iterable_;
use phpDocumentor\Reflection\Types\Mixed_;
use phpDocumentor\Reflection\Types\Never_;
use phpDocumentor\Reflection\Types\Null_;
use phpDocumentor\Reflection\Types\Nullable;
use phpDocumentor\Reflection\Types\Object_;
use phpDocumentor\Reflection\Types\Parent_;
use phpDocumentor\Reflection\Types\Self_;
use phpDocumentor\Reflection\Types\Static_;
use phpDocumentor\Reflection\Types\String_;
use phpDocumentor\Reflection\Types\This;
use phpDocumentor\Reflection\Types\Void_;

/**
 * The one place docblocks are parsed. Wraps
 * `phpdocumentor/reflection-docblock`, which handles the tag walking, the
 * psalm-/phpstan- syntax extensions, and the class-name resolution against a
 * namespace + import context. All this wrapper adds is the bridge from
 * phpDocumentor's Type hierarchy to the project's own {@see Type}.
 */
final class DocblockParser
{
    private static ?DocBlockFactoryInterface $factory = null;

    /**
     * Resolved `@var`, `@return`, and `@param` tags (native +
     * `psalm-`/`phpstan-` spellings; the library folds them itself) with every
     * class name already fully qualified.
     *
     * @param array<string, string> $aliases short-alias => fully-qualified name
     * @return array{return?: Type, var?: Type, params?: array<string, Type>}
     */
    public static function extractResolvedTypedTags(string $docblock, string $namespace, array $aliases): array
    {
        $doc = self::factory()->create($docblock, new Context($namespace, $aliases));
        $tags = [];

        foreach ($doc->getTagsByName('var') as $tag) {
            if ($tag instanceof Var_) {
                $type = self::toType($tag->getType());
                if ($type !== null) {
                    $tags['var'] = $type;
                }
            }
        }
        foreach ($doc->getTagsByName('return') as $tag) {
            if ($tag instanceof Return_) {
                $type = self::toType($tag->getType());
                if ($type !== null) {
                    $tags['return'] = $type;
                }
            }
        }
        $params = [];
        foreach ($doc->getTagsByName('param') as $tag) {
            if (!$tag instanceof Param) {
                continue;
            }
            $name = $tag->getVariableName();
            $type = self::toType($tag->getType());
            if ($name !== null && $type !== null) {
                $params[$name] = $type;
            }
        }
        if ($params !== []) {
            $tags['params'] = $params;
        }

        return $tags;
    }

    public static function extractDescription(string $docblock): string
    {
        $doc = self::factory()->create($docblock);
        $summary = $doc->getSummary();
        $body = (string) $doc->getDescription();
        if ($summary === '') {
            return $body;
        }
        return $body === '' ? $summary : $summary . "\n" . $body;
    }

    private static function factory(): DocBlockFactoryInterface
    {
        return self::$factory ??= DocBlockFactory::createInstance();
    }

    private static function toType(?PhpDocType $type): ?Type
    {
        if ($type === null) {
            return null;
        }
        if ($type instanceof Nullable) {
            $inner = self::toType($type->getActualType());
            return $inner === null ? null : TypeFactory::nullable($inner);
        }
        if ($type instanceof Intersection) {
            $members = self::aggregateMembers($type);
            return $members === [] ? null : TypeFactory::intersection($members);
        }
        if ($type instanceof Compound) {
            $members = self::aggregateMembers($type);
            return $members === [] ? null : TypeFactory::union($members);
        }
        if ($type instanceof Array_) {
            $value = self::toType($type->getValueType());
            return TypeFactory::primitive('array', $value !== null ? [$value] : []);
        }
        if ($type instanceof Iterable_) {
            $value = self::toType($type->getValueType());
            return TypeFactory::primitive('iterable', $value !== null ? [$value] : []);
        }
        if ($type instanceof Object_) {
            $fqsen = $type->getFqsen();
            return $fqsen === null
                ? TypeFactory::primitive('object')
                : TypeFactory::className(ltrim((string) $fqsen, '\\'));
        }
        return match (true) {
            $type instanceof Integer => TypeFactory::primitive('int'),
            $type instanceof String_ => TypeFactory::primitive('string'),
            $type instanceof Boolean => TypeFactory::primitive('bool'),
            $type instanceof Float_ => TypeFactory::primitive('float'),
            $type instanceof Null_ => TypeFactory::primitive('null'),
            $type instanceof Mixed_ => TypeFactory::primitive('mixed'),
            $type instanceof Void_ => TypeFactory::primitive('void'),
            $type instanceof Never_ => TypeFactory::primitive('never'),
            $type instanceof Callable_ => TypeFactory::primitive('callable'),
            $type instanceof Self_, $type instanceof This => TypeFactory::primitive('self'),
            $type instanceof Static_ => TypeFactory::primitive('static'),
            $type instanceof Parent_ => TypeFactory::primitive('parent'),
            default => null,
        };
    }

    /**
     * @return list<Type>
     */
    private static function aggregateMembers(AggregatedType $type): array
    {
        $members = [];
        foreach ($type as $member) {
            $converted = self::toType($member);
            if ($converted !== null) {
                $members[] = $converted;
            }
        }
        return $members;
    }
}
