<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Knowledge;

use Attribute;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\ClasslikeConstantInfo;
use Firehed\PhpLsp\Domain\ClasslikeConstantName;
use Firehed\PhpLsp\Domain\ClasslikeName;
use Firehed\PhpLsp\Domain\ConstantInfo;
use Firehed\PhpLsp\Domain\ConstantName;
use Firehed\PhpLsp\Domain\EnumCaseInfo;
use Firehed\PhpLsp\Domain\EnumCaseName;
use Firehed\PhpLsp\Domain\EnumImplicits;
use Firehed\PhpLsp\Domain\FileUri;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\FunctionName;
use Firehed\PhpLsp\Domain\LateBindingKeyword;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\ParameterInfo;
use Firehed\PhpLsp\Domain\PrimitiveType;
use Firehed\PhpLsp\Domain\PropertyInfo;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\QualifiedName;
use Firehed\PhpLsp\Domain\TraitAlias;
use Firehed\PhpLsp\Domain\TypeFactory;
use Firehed\PhpLsp\Domain\Visibility;
use InvalidArgumentException;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

/**
 * Builds concrete info values from parsed {@see FileDeclarations}: three
 * bulk builders for the open-document write path, and three per-name lookups
 * for the filesystem backend. Each method touches only its own kind's
 * declaration list, so a kind cannot answer for another and no route through
 * a marker type is needed.
 *
 * A trait rather than a class: the two consumers ({@see FilesystemBackend},
 * {@see DocumentSymbolSink}) each own the construction they need, so the
 * "factory" role need not be a separate service.
 */
trait BuildsInfoFromDeclarationsTrait
{
    /**
     * @return list<ClassInfo>
     */
    public function allClassInfosIn(FileDeclarations $declarations, string $filePath): array
    {
        $uri = FileUri::fromPath($filePath);

        return $this->dedupAndBuild(
            $declarations->classLikes,
            NameKind::ClassLike,
            fn($decl): ClassInfo => $this->classInfoFromNode($decl->node, $uri),
        );
    }

    /**
     * @return list<ConstantInfo>
     */
    public function allConstantInfosIn(FileDeclarations $declarations, string $filePath): array
    {
        return $this->dedupAndBuild(
            $declarations->constants,
            NameKind::Constant,
            fn($decl): ConstantInfo => $this->constantInfoFromGlobalDeclaration($decl->node, $decl->name, $filePath),
        );
    }

    /**
     * @return list<FunctionInfo>
     */
    public function allFunctionInfosIn(FileDeclarations $declarations, string $filePath): array
    {
        return $this->dedupAndBuild(
            $declarations->functions,
            NameKind::Function_,
            fn($decl): FunctionInfo => $this->functionInfoFromNode($decl->node, $decl->name, $filePath),
        );
    }

    public function classInfoFrom(
        FileDeclarations $declarations,
        ClasslikeName $name,
        string $filePath,
    ): ?ClassInfo {
        $target = NameKind::ClassLike->normalize($name->qualifiedName);
        foreach ($declarations->classLikes as $declaration) {
            if (NameKind::ClassLike->normalize($declaration->name) === $target) {
                return $this->classInfoFromNode($declaration->node, FileUri::fromPath($filePath));
            }
        }

        return null;
    }

    public function constantInfoFrom(
        FileDeclarations $declarations,
        ConstantName $name,
        string $filePath,
    ): ?ConstantInfo {
        $target = $name->kind->normalize($name->qualifiedName);
        foreach ($declarations->constants as $declaration) {
            if ($name->kind->normalize($declaration->name) === $target) {
                return $this->constantInfoFromGlobalDeclaration($declaration->node, $declaration->name, $filePath);
            }
        }

        return null;
    }

    public function functionInfoFrom(
        FileDeclarations $declarations,
        FunctionName $name,
        string $filePath,
    ): ?FunctionInfo {
        $target = $name->kind->normalize($name->qualifiedName);
        foreach ($declarations->functions as $declaration) {
            if ($name->kind->normalize($declaration->name) === $target) {
                return $this->functionInfoFromNode($declaration->node, $declaration->name, $filePath);
            }
        }

        return null;
    }

    private function classInfoFromNode(Stmt\ClassLike $node, string $uri): ClassInfo
    {
        $className = $this->resolveClasslikeName($node);
        $filePath = FileUri::toPath($uri);
        $traitUse = $this->extractTraitUse($node);

        return new ClassInfo(
            name: $className,
            kind: $this->determineKind($node),
            isAbstract: $node instanceof Stmt\Class_ && $node->isAbstract(),
            isFinal: $node instanceof Stmt\Class_ && $node->isFinal(),
            isReadonly: $node instanceof Stmt\Class_ && $node->isReadonly(),
            isAttribute: $this->isAttributeNode($node),
            parent: $this->resolveParent($node),
            interfaces: $this->extractInterfaces($node),
            traits: $traitUse['traits'],
            methods: $this->extractMethods($node, $className, $filePath),
            properties: $this->extractProperties($node, $className, $filePath),
            constants: $this->extractConstants($node, $className, $filePath),
            enumCases: $this->extractEnumCases($node, $className, $filePath),
            docblock: $node->getDocComment()?->getText(),
            file: $filePath,
            line: $node->getStartLine(),
            traitExclusions: $traitUse['exclusions'],
            traitAliases: $traitUse['aliases'],
        );
    }

    /**
     * @param Node\Const_|Expr\FuncCall $node
     */
    private function constantInfoFromGlobalDeclaration(
        Node\Const_|Expr\FuncCall $node,
        QualifiedName $name,
        string $filePath,
    ): ConstantInfo {
        // php-parser attaches a doc comment to the outer statement — `Stmt\Const_`
        // for a `const` declarator, `Stmt\Expression` for a `define()` call — so a
        // declarator or expression asked directly for its comment reads null.
        // Consult the parent first, then the node itself.
        $parent = $node->getAttribute('parent');
        $doc = ($parent instanceof Node ? $parent->getDocComment() : null) ?? $node->getDocComment();

        return new ConstantInfo(
            name: new ConstantName($name),
            type: null,
            docblock: $doc?->getText(),
            file: $filePath,
            line: $node->getStartLine(),
        );
    }

    /**
     * @template TDecl of Declaration<Node>
     * @template TInfo
     * @param list<TDecl> $declarations
     * @param \Closure(TDecl): TInfo $build
     * @return list<TInfo>
     */
    private function dedupAndBuild(array $declarations, NameKind $kind, \Closure $build): array
    {
        $seen = [];
        $infos = [];

        foreach ($declarations as $declaration) {
            $key = $kind->normalize($declaration->name);
            if (array_key_exists($key, $seen)) {
                continue;
            }
            $seen[$key] = true;
            $infos[] = $build($declaration);
        }

        return $infos;
    }

    private function determineKind(Stmt\ClassLike $node): ClassKind
    {
        return match (true) {
            $node instanceof Stmt\Interface_ => ClassKind::Interface_,
            $node instanceof Stmt\Trait_ => ClassKind::Trait_,
            $node instanceof Stmt\Enum_ => ClassKind::Enum_,
            default => ClassKind::Class_,
        };
    }

    /**
     * @return array<string, ClasslikeConstantInfo>
     */
    private function extractConstants(Stmt\ClassLike $node, ClasslikeName $className, string $filePath): array
    {
        $constants = [];
        $parentClass = $this->resolveParent($node);

        foreach ($node->stmts as $stmt) {
            if (!$stmt instanceof Stmt\ClassConst) {
                continue;
            }

            foreach ($stmt->consts as $const) {
                $name = $const->name->toString();
                $constants[$name] = new ClasslikeConstantInfo(
                    name: new ClasslikeConstantName($className, $name),
                    visibility: $this->visibilityFromFlags($stmt->flags),
                    isFinal: $stmt->isFinal(),
                    type: TypeFactory::fromNode(
                        $stmt->type,
                        $className->qualifiedName->fullyQualifiedName(),
                        $parentClass?->qualifiedName->fullyQualifiedName(),
                    ),
                    docblock: $stmt->getDocComment()?->getText(),
                    file: $filePath,
                    line: $stmt->getStartLine(),
                );
            }
        }

        return $constants;
    }

    /**
     * @return array<string, EnumCaseInfo>
     */
    private function extractEnumCases(Stmt\ClassLike $node, ClasslikeName $className, string $filePath): array
    {
        if (!$node instanceof Stmt\Enum_) {
            return [];
        }

        $cases = [];

        foreach ($node->stmts as $stmt) {
            if (!$stmt instanceof Stmt\EnumCase) {
                continue;
            }

            $name = $stmt->name->toString();
            $cases[$name] = new EnumCaseInfo(
                name: new EnumCaseName($className, $name),
                backingValue: $this->extractEnumCaseBackingValue($stmt),
                docblock: $stmt->getDocComment()?->getText(),
                file: $filePath,
                line: $stmt->getStartLine(),
            );
        }

        return $cases;
    }

    private function extractEnumCaseBackingValue(Stmt\EnumCase $case): int|string|null
    {
        return match (true) {
            $case->expr instanceof Scalar\Int_,
            $case->expr instanceof Scalar\String_ => $case->expr->value,
            default => null,
        };
    }

    /**
     * @return list<ClasslikeName>
     */
    private function extractInterfaces(Stmt\ClassLike $node): array
    {
        $interfaces = [];

        if ($node instanceof Stmt\Class_ || $node instanceof Stmt\Enum_) {
            foreach ($node->implements as $interface) {
                $interfaces[] = $this->resolveNameToClasslikeName($interface);
            }
        }

        if ($node instanceof Stmt\Interface_) {
            foreach ($node->extends as $interface) {
                $interfaces[] = $this->resolveNameToClasslikeName($interface);
            }
        }

        if ($node instanceof Stmt\Enum_) {
            $interfaces = array_merge($interfaces, EnumImplicits::interfaces($node->scalarType !== null));
        }

        return $interfaces;
    }

    /**
     * @return array<string, MethodInfo>
     */
    private function extractMethods(Stmt\ClassLike $node, ClasslikeName $className, string $filePath): array
    {
        $methods = [];
        $parentClass = $this->resolveParent($node);

        foreach ($node->stmts as $stmt) {
            if (!$stmt instanceof Stmt\ClassMethod) {
                continue;
            }

            $name = $stmt->name->toString();
            $methods[$name] = new MethodInfo(
                name: new MethodName($className, $name),
                visibility: $this->visibilityFromFlags($stmt->flags),
                isStatic: $stmt->isStatic(),
                isAbstract: $stmt->isAbstract(),
                isFinal: $stmt->isFinal(),
                parameters: $this->extractParameters($stmt->params, $className, $parentClass),
                returnType: TypeFactory::fromNode(
                    $stmt->returnType,
                    $className->qualifiedName->fullyQualifiedName(),
                    $parentClass?->qualifiedName->fullyQualifiedName(),
                    preserveLateBinding: true,
                ),
                docblock: $stmt->getDocComment()?->getText(),
                file: $filePath,
                line: $stmt->getStartLine(),
            );
        }

        if ($node instanceof Stmt\Enum_) {
            $methods = array_merge($methods, EnumImplicits::methods($className, $this->enumScalarType($node)));
        }

        return $methods;
    }

    /**
     * @param array<Param> $params
     * @return list<ParameterInfo>
     */
    private function extractParameters(array $params, ClasslikeName $className, ?ClasslikeName $parentClass): array
    {
        $result = [];
        foreach ($params as $position => $param) {
            $info = $this->parameterFromNode(
                $param,
                $position,
                $className->qualifiedName->fullyQualifiedName(),
                $parentClass?->qualifiedName->fullyQualifiedName(),
            );
            if ($info !== null) {
                $result[] = $info;
            }
        }
        return $result;
    }

    /**
     * @return array<string, PropertyInfo>
     */
    private function extractProperties(Stmt\ClassLike $node, ClasslikeName $className, string $filePath): array
    {
        $properties = [];

        if ($node instanceof Stmt\Enum_) {
            $properties = EnumImplicits::properties($className, $this->enumScalarType($node));
        }
        $parentClass = $this->resolveParent($node);

        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    $name = $prop->name->toString();
                    $properties[$name] = new PropertyInfo(
                        name: new PropertyName($className, $name),
                        visibility: $this->visibilityFromFlags($stmt->flags),
                        isStatic: $stmt->isStatic(),
                        isReadonly: $stmt->isReadonly(),
                        isPromoted: false,
                        type: TypeFactory::fromNode(
                            $stmt->type,
                            $className->qualifiedName->fullyQualifiedName(),
                            $parentClass?->qualifiedName->fullyQualifiedName(),
                        ),
                        docblock: $stmt->getDocComment()?->getText(),
                        file: $filePath,
                        line: $stmt->getStartLine(),
                    );
                }
            }

            if ($stmt instanceof Stmt\ClassMethod && $stmt->name->toLowerString() === '__construct') {
                foreach ($stmt->params as $param) {
                    if (!$this->isPromotedProperty($param)) {
                        continue;
                    }
                    if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                        continue;
                    }

                    $name = $param->var->name;
                    $properties[$name] = new PropertyInfo(
                        name: new PropertyName($className, $name),
                        visibility: $this->visibilityFromFlags($param->flags),
                        isStatic: false,
                        isReadonly: ($param->flags & Modifiers::READONLY) !== 0,
                        isPromoted: true,
                        type: TypeFactory::fromNode(
                            $param->type,
                            $className->qualifiedName->fullyQualifiedName(),
                            $parentClass?->qualifiedName->fullyQualifiedName(),
                        ),
                        docblock: $param->getDocComment()?->getText(),
                        file: $filePath,
                        line: $param->getStartLine(),
                    );
                }
            }
        }

        return $properties;
    }

    /**
     * @return array{traits: list<ClasslikeName>, exclusions: array<string, list<string>>, aliases: list<TraitAlias>}
     */
    private function extractTraitUse(Stmt\ClassLike $node): array
    {
        $traits = [];
        $exclusions = [];
        $aliases = [];

        foreach ($node->stmts as $stmt) {
            if (!$stmt instanceof Stmt\TraitUse) {
                continue;
            }
            foreach ($stmt->traits as $trait) {
                $traits[] = $this->resolveNameToClasslikeName($trait);
            }
            foreach ($stmt->adaptations as $adaptation) {
                if ($adaptation instanceof Stmt\TraitUseAdaptation\Precedence) {
                    $method = $adaptation->method->toString();
                    foreach ($adaptation->insteadof as $loser) {
                        $key = NameKind::ClassLike->normalize(
                            $this->resolveNameToClasslikeName($loser)->qualifiedName,
                        );
                        $exclusions[$key][] = $method;
                    }
                    continue;
                }
                if ($adaptation instanceof Stmt\TraitUseAdaptation\Alias) {
                    $aliases[] = new TraitAlias(
                        trait: $adaptation->trait !== null
                            ? $this->resolveNameToClasslikeName($adaptation->trait)
                            : null,
                        method: $adaptation->method->toString(),
                        newName: $adaptation->newName?->toString(),
                        newVisibility: $adaptation->newModifier !== null
                            ? $this->visibilityFromFlags($adaptation->newModifier)
                            : null,
                    );
                }
            }
        }

        return ['traits' => $traits, 'exclusions' => $exclusions, 'aliases' => $aliases];
    }

    private function enumScalarType(Stmt\Enum_ $enum): ?PrimitiveType
    {
        if ($enum->scalarType === null) {
            return null;
        }

        return TypeFactory::primitive($enum->scalarType->toString());
    }

    private function functionInfoFromNode(
        Stmt\Function_ $node,
        QualifiedName $name,
        string $filePath,
    ): FunctionInfo {
        $params = [];
        foreach ($node->params as $position => $param) {
            $paramInfo = $this->parameterFromNode($param, $position);
            if ($paramInfo !== null) {
                $params[] = $paramInfo;
            }
        }

        return new FunctionInfo(
            name: new FunctionName($name),
            parameters: $params,
            returnType: TypeFactory::fromNode($node->returnType),
            docblock: $node->getDocComment()?->getText(),
            file: $filePath,
            line: $node->getStartLine(),
        );
    }

    /**
     * A class is a PHP attribute when it is itself declared with `#[Attribute]`.
     * Only classes can be attributes; interfaces, traits, and enums cannot.
     */
    private function isAttributeNode(Stmt\ClassLike $node): bool
    {
        if (!$node instanceof Stmt\Class_) {
            return false;
        }

        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                $attrFqn = $this->resolveNameToClasslikeName($attr->name)->qualifiedName->fullyQualifiedName();
                if ($attrFqn === Attribute::class) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isPromotedProperty(Param $param): bool
    {
        return ($param->flags & Modifiers::VISIBILITY_MASK) !== 0;
    }

    private function parameterFromNode(
        Param $param,
        int $position,
        ?string $selfContext = null,
        ?string $parentContext = null,
    ): ?ParameterInfo {
        if (!$param->var instanceof Variable || !is_string($param->var->name)) {
            return null;
        }

        $defaultValue = null;
        if ($param->default !== null) {
            $printer = new PrettyPrinter();
            $defaultValue = $printer->prettyPrintExpr($param->default);
        }

        return new ParameterInfo(
            name: $param->var->name,
            type: TypeFactory::fromNode($param->type, $selfContext, $parentContext),
            hasDefault: $param->default !== null,
            defaultValue: $defaultValue,
            position: $position,
            isVariadic: $param->variadic,
            isPassedByReference: $param->byRef,
        );
    }

    private function resolveClasslikeName(Stmt\ClassLike $node): ClasslikeName
    {
        $fqn = LateBindingKeyword::Self->resolveIn($node);
        // @codeCoverageIgnoreStart
        if ($fqn === null) {
            throw new InvalidArgumentException('Cannot create ClassInfo for anonymous class');
        }
        // @codeCoverageIgnoreEnd
        return ClasslikeName::fromFullyQualified($fqn);
    }

    private function resolveNameToClasslikeName(Node\Name $name): ClasslikeName
    {
        // TreeAnnotator's NameResolver replaces class-context names with
        // FullyQualified in-place (default replaceNodes mode), so a plain
        // toString() reads the resolved FQN.
        /** @var class-string $fqn */
        $fqn = $name->toString();
        return ClasslikeName::fromFullyQualified($fqn);
    }

    private function resolveParent(Stmt\ClassLike $node): ?ClasslikeName
    {
        if (!$node instanceof Stmt\Class_ || $node->extends === null) {
            return null;
        }

        return $this->resolveNameToClasslikeName($node->extends);
    }

    private function visibilityFromFlags(int $flags): Visibility
    {
        if (($flags & Modifiers::PRIVATE) !== 0) {
            return Visibility::Private;
        }
        if (($flags & Modifiers::PROTECTED) !== 0) {
            return Visibility::Protected;
        }
        return Visibility::Public;
    }
}
