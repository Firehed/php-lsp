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
use Firehed\PhpLsp\Domain\DeclaredSymbol;
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
use Firehed\PhpLsp\Domain\SymbolInfoInterface;
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
 * The one place a {@see NameKind} picks a declaration list and a builder, which is
 * what lets {@see SymbolBackendInterface} carry a single lookup and a single registration
 * (Plan 0002 §5.6).
 *
 * Lookup is a filter over {@see allIn()} rather than its own scan: RFC 1 §5.1
 * forbids a derived verb forking from the one it derives from, and a second scan is
 * how the on-disk read path and the open-document write path came to disagree about
 * which declarations count.
 */
final readonly class DeclarationSymbolInfoFactory
{
    /**
     * Every symbol the file declares, at any depth. Of duplicates the first wins —
     * the one PHP would define.
     *
     * @return list<DeclaredSymbol>
     */
    public function allIn(FileDeclarations $declarations, string $filePath): array
    {
        $symbols = [];
        $seen = [];

        foreach ($declarations->classLikes as $declaration) {
            $info = $this->classInfoFromNode($declaration->node, FileUri::fromPath($filePath));
            self::collect($symbols, $seen, $declaration->name, NameKind::ClassLike, $info);
        }
        foreach ($declarations->functions as $declaration) {
            $info = $this->functionInfoFromNode($declaration->node, $declaration->name, $filePath);
            self::collect($symbols, $seen, $declaration->name, NameKind::Function_, $info);
        }
        foreach ($declarations->constants as $declaration) {
            $info = $this->constantInfoFromGlobalDeclaration(
                $declaration->node,
                $declaration->name,
                $filePath,
            );
            self::collect($symbols, $seen, $declaration->name, NameKind::Constant, $info);
        }

        return $symbols;
    }

    public function fromDeclarations(
        FileDeclarations $declarations,
        QualifiedName $name,
        NameKind $kind,
        string $filePath,
    ): ?SymbolInfoInterface {
        $target = $kind->normalize($name);

        foreach ($this->allIn($declarations, $filePath) as $symbol) {
            if ($symbol->kind === $kind && $kind->normalize($symbol->name) === $target) {
                return $symbol->info;
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
        $expr = $case->expr;
        if ($expr instanceof Scalar\Int_) {
            return $expr->value;
        }
        if ($expr instanceof Scalar\String_) {
            return $expr->value;
        }
        return null;
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

        return new PrimitiveType($enum->scalarType->toString());
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

    /**
     * @param list<DeclaredSymbol> $symbols
     * @param array<string, true> $seen
     */
    private static function collect(
        array &$symbols,
        array &$seen,
        QualifiedName $name,
        NameKind $kind,
        SymbolInfoInterface $info,
    ): void {
        $key = $kind->keyFor($name);
        if (array_key_exists($key, $seen)) {
            return;
        }

        $seen[$key] = true;
        $symbols[] = new DeclaredSymbol($name, $kind, $info);
    }
}
