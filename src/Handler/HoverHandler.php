<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Domain\ClassInfo;
use Firehed\PhpLsp\Domain\ClassKind;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\PropertyInfo as DomainPropertyInfo;
use Firehed\PhpLsp\Domain\PropertyName;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Index\NodeAtPosition;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Repository\ClassRepository;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\TypeInference\TypeResolverInterface;
use Firehed\PhpLsp\Utility\DocblockParser;
use Firehed\PhpLsp\Utility\ExpressionTypeResolver;
use Firehed\PhpLsp\Utility\ScopeFinder;
use Firehed\PhpLsp\Utility\TypeFormatter;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use ReflectionException;
use ReflectionFunction;

final class HoverHandler implements HandlerInterface
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly ParserService $parser,
        private readonly ClassRepository $classRepository,
        private readonly MemberResolver $memberResolver,
        private readonly ?TypeResolverInterface $typeResolver = null,
    ) {
    }

    public function supports(string $method): bool
    {
        return $method === 'textDocument/hover';
    }

    /**
     * @return array{contents: string}|null
     */
    public function handle(Message $message): ?array
    {
        $params = $message->params ?? [];

        $textDocument = $params['textDocument'] ?? [];
        if (!is_array($textDocument)) {
            return null;
        }
        $uri = $textDocument['uri'] ?? '';
        if (!is_string($uri)) {
            return null;
        }

        $position = $params['position'] ?? [];
        if (!is_array($position)) {
            return null;
        }
        $line = $position['line'] ?? 0;
        $character = $position['character'] ?? 0;
        if (!is_int($line) || !is_int($character)) {
            return null;
        }

        $document = $this->documentManager->get($uri);
        if ($document === null) {
            return null;
        }

        $ast = $this->parser->parse($document);
        if ($ast === null) {
            return null;
        }

        $offset = $document->offsetAt($line, $character);
        $nodeFinder = new NodeAtPosition();
        $node = $nodeFinder->find($ast, $offset);

        if ($node === null) {
            return null;
        }

        $hoverContent = $this->getHoverContent($node, $ast);

        if ($hoverContent === null) {
            return null;
        }

        return ['contents' => $hoverContent];
    }

    /**
     * @param array<Stmt> $ast
     */
    private function getHoverContent(Node $node, array $ast): ?string
    {
        // Name node - could be class reference or function call
        if ($node instanceof Name) {
            $parent = $node->getAttribute('parent');

            // Function call: check for user-defined function first, then built-in
            if ($parent instanceof FuncCall) {
                $functionHover = $this->getFunctionHover($node->toString(), $ast);
                if ($functionHover !== null) {
                    return $functionHover;
                }
                return $this->getBuiltinFunctionHover($node->toString());
            }

            // Static method call: ClassName::method()
            if ($parent instanceof StaticCall) {
                return $this->getClassHover($node);
            }

            // Static property fetch: ClassName::$property
            if ($parent instanceof StaticPropertyFetch) {
                return $this->getClassHover($node);
            }

            // Fall through to class hover for class references
            return $this->getClassHover($node);
        }

        // Identifier node - could be method name, property name, or function call
        if ($node instanceof Identifier) {
            $parent = $node->getAttribute('parent');

            // Method call: $obj->method() or $this->method()
            if ($parent instanceof MethodCall) {
                return $this->getMethodHover($parent, $ast);
            }

            // Static method call: ClassName::method()
            if ($parent instanceof StaticCall) {
                return $this->getStaticMethodHover($parent);
            }

            // Property fetch: $obj->property or $this->property
            if ($parent instanceof PropertyFetch) {
                return $this->getPropertyHover($parent, $ast);
            }

            // Static property fetch: ClassName::$property
            if ($parent instanceof StaticPropertyFetch) {
                return $this->getStaticPropertyHover($parent);
            }

            if ($parent instanceof FuncCall) {
                return $this->getFunctionHover($node->toString(), $ast);
            }
        }

        return null;
    }

    private function getClassHover(Name $node): ?string
    {
        $classNameStr = ScopeFinder::resolveName($node);

        /** @var class-string $classNameStr */
        $classInfo = $this->classRepository->get(new ClassName($classNameStr));

        if ($classInfo === null) {
            return null;
        }

        return $this->formatClassHover($classInfo);
    }

    private function formatClassHover(ClassInfo $classInfo): string
    {
        $parts = [];

        // Add docblock if present
        if ($classInfo->docblock !== null) {
            $parts[] = DocblockParser::extractDescription($classInfo->docblock);
        }

        // Add signature
        $keyword = match ($classInfo->kind) {
            ClassKind::Interface_ => 'interface',
            ClassKind::Trait_ => 'trait',
            ClassKind::Enum_ => 'enum',
            default => 'class',
        };

        $signature = $keyword . ' ' . $classInfo->name->shortName();

        if ($classInfo->kind === ClassKind::Class_) {
            if ($classInfo->parent !== null) {
                $signature .= ' extends ' . $classInfo->parent->shortName();
            }
            if ($classInfo->interfaces !== []) {
                $implements = array_map(fn($n) => $n->shortName(), $classInfo->interfaces);
                $signature .= ' implements ' . implode(', ', $implements);
            }
        }

        if ($classInfo->kind === ClassKind::Interface_ && $classInfo->interfaces !== []) {
            $extends = array_map(fn($n) => $n->shortName(), $classInfo->interfaces);
            $signature .= ' extends ' . implode(', ', $extends);
        }

        $parts[] = '```php' . "\n" . $signature . "\n```";

        return implode("\n\n", $parts);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function getFunctionHover(string $functionName, array $ast): ?string
    {
        $finder = new class ($functionName) extends NodeVisitorAbstract {
            public ?Stmt\Function_ $found = null;

            public function __construct(private readonly string $functionName)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Stmt\Function_ && $node->name->toString() === $this->functionName) {
                    $this->found = $node;
                    return NodeTraverser::STOP_TRAVERSAL;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($finder);
        $traverser->traverse($ast);

        if ($finder->found === null) {
            return null;
        }

        return $this->formatFunctionHover($finder->found);
    }

    private function formatFunctionHover(Stmt\Function_ $node): string
    {
        $parts = [];

        // Add docblock if present
        $docComment = $node->getDocComment();
        if ($docComment !== null) {
            $parts[] = DocblockParser::extractDescription($docComment->getText());
        }

        // Build signature
        $params = [];
        foreach ($node->params as $param) {
            $paramStr = '';
            if ($param->type !== null) {
                $paramStr .= TypeFormatter::formatNode($param->type) . ' ';
            }
            $var = $param->var;
            if ($var instanceof Node\Expr\Variable && is_string($var->name)) {
                $paramStr .= '$' . $var->name;
            }
            $params[] = $paramStr;
        }

        $signature = 'function ' . $node->name->toString() . '(' . implode(', ', $params) . ')';

        if ($node->returnType !== null) {
            $signature .= ': ' . TypeFormatter::formatNode($node->returnType);
        }

        $parts[] = '```php' . "\n" . $signature . "\n```";

        return implode("\n\n", $parts);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function getMethodHover(MethodCall $call, array $ast): ?string
    {
        $methodName = $call->name;
        if (!$methodName instanceof Identifier) {
            return null;
        }

        $className = ExpressionTypeResolver::resolveExpressionType($call->var, $ast, $this->typeResolver);
        if ($className === null) {
            return null;
        }

        return $this->getMethodHoverForClass($className, $methodName->toString());
    }

    private function getStaticMethodHover(StaticCall $call): ?string
    {
        $methodName = $call->name;
        if (!$methodName instanceof Identifier) {
            return null;
        }

        $class = $call->class;
        if (!$class instanceof Name) {
            return null;
        }

        $className = ScopeFinder::resolveName($class);

        return $this->getMethodHoverForClass($className, $methodName->toString());
    }

    /**
     * @param array<Stmt> $ast
     */
    private function getPropertyHover(PropertyFetch $fetch, array $ast): ?string
    {
        $propertyName = $fetch->name;
        if (!$propertyName instanceof Identifier) {
            return null;
        }

        $className = ExpressionTypeResolver::resolveExpressionType($fetch->var, $ast, $this->typeResolver);
        if ($className === null) {
            return null;
        }

        return $this->getPropertyHoverForClass($className, $propertyName->toString());
    }

    private function getStaticPropertyHover(StaticPropertyFetch $fetch): ?string
    {
        $propertyName = $fetch->name;
        if (!$propertyName instanceof Node\VarLikeIdentifier) {
            return null;
        }

        $class = $fetch->class;
        if (!$class instanceof Name) {
            return null;
        }

        $className = ScopeFinder::resolveName($class);

        return $this->getPropertyHoverForClass($className, $propertyName->toString());
    }

    private function getMethodHoverForClass(string $classNameStr, string $methodNameStr): ?string
    {
        /** @var class-string $classNameStr */
        $className = new ClassName($classNameStr);
        $methodName = new MethodName($methodNameStr);

        // Hover shows all members regardless of caller context
        $methodInfo = $this->memberResolver->findMethod($className, $methodName, Visibility::Private);
        if ($methodInfo === null) {
            return null;
        }

        return $this->formatMethodHover($methodInfo);
    }

    private function getPropertyHoverForClass(string $classNameStr, string $propertyNameStr): ?string
    {
        /** @var class-string $classNameStr */
        $className = new ClassName($classNameStr);
        $propertyName = new PropertyName($propertyNameStr);

        // Hover shows all members regardless of caller context
        $propertyInfo = $this->memberResolver->findProperty($className, $propertyName, Visibility::Private);
        if ($propertyInfo === null) {
            return null;
        }

        return $this->formatPropertyHover($propertyInfo);
    }

    private function formatMethodHover(MethodInfo $method): string
    {
        $parts = [];

        if ($method->docblock !== null) {
            $parts[] = DocblockParser::extractDescription($method->docblock);
        }

        $visibility = $method->visibility->format() . ' ';
        $static = $method->isStatic ? 'static ' : '';

        $params = [];
        foreach ($method->parameters as $param) {
            $params[] = $param->format(showDefault: true);
        }

        $signature = $visibility . $static . 'function ' . $method->name->name
            . '(' . implode(', ', $params) . ')';

        if ($method->returnType !== null) {
            $signature .= ': ' . $method->returnType;
        }

        $parts[] = '```php' . "\n" . $signature . "\n```";

        return implode("\n\n", $parts);
    }

    private function formatPropertyHover(DomainPropertyInfo $property): string
    {
        $parts = [];

        if ($property->docblock !== null) {
            $parts[] = DocblockParser::extractDescription($property->docblock);
        }

        $visibility = $property->visibility->format() . ' ';
        $static = $property->isStatic ? 'static ' : '';
        $readonly = $property->isReadonly ? 'readonly ' : '';

        $type = $property->type !== null ? $property->type . ' ' : '';

        $signature = $visibility . $static . $readonly . $type . '$' . $property->name->name;

        $parts[] = '```php' . "\n" . $signature . "\n```";

        return implode("\n\n", $parts);
    }

    private function getBuiltinFunctionHover(string $functionName): ?string
    {
        try {
            $reflection = new ReflectionFunction($functionName);
            return $this->formatReflectionFunction($reflection);
        } catch (ReflectionException) {
            return null;
        }
    }

    private function formatReflectionFunction(ReflectionFunction $func): string
    {
        $parts = [];

        $docComment = $func->getDocComment();
        if ($docComment !== false) {
            $parts[] = DocblockParser::extractDescription($docComment);
        }

        $params = [];
        foreach ($func->getParameters() as $param) {
            $paramStr = '';
            $type = $param->getType();
            if ($type !== null) {
                $paramStr .= TypeFormatter::formatReflection($type) . ' ';
            }
            if ($param->isVariadic()) {
                $paramStr .= '...';
            }
            $paramStr .= '$' . $param->getName();
            if ($param->isOptional() && !$param->isVariadic()) {
                $paramStr .= ' = ...';
            }
            $params[] = $paramStr;
        }

        $signature = 'function ' . $func->getName() . '(' . implode(', ', $params) . ')';

        $returnType = $func->getReturnType();
        if ($returnType !== null) {
            $signature .= ': ' . TypeFormatter::formatReflection($returnType);
        }

        $parts[] = '```php' . "\n" . $signature . "\n```";

        return implode("\n\n", $parts);
    }
}
