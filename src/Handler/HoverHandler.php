<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Index\ComposerClassLocator;
use Firehed\PhpLsp\Index\NodeAtPosition;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\Message;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionProperty;

final class HoverHandler implements HandlerInterface
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly ParserService $parser,
        private readonly ?ComposerClassLocator $classLocator,
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

        $hoverContent = $this->getHoverContent($node, $ast, $document);

        if ($hoverContent === null) {
            return null;
        }

        return ['contents' => $hoverContent];
    }

    /**
     * @param array<Stmt> $ast
     */
    private function getHoverContent(Node $node, array $ast, TextDocument $document): ?string
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
                return $this->getClassHover($node, $ast, $document);
            }

            // Static property fetch: ClassName::$property
            if ($parent instanceof StaticPropertyFetch) {
                return $this->getClassHover($node, $ast, $document);
            }

            // Fall through to class hover for class references
            return $this->getClassHover($node, $ast, $document);
        }

        // Identifier node - could be method name, property name, or function call
        if ($node instanceof Identifier) {
            $parent = $node->getAttribute('parent');

            // Method call: $obj->method() or $this->method()
            if ($parent instanceof MethodCall) {
                return $this->getMethodHover($parent, $ast, $document);
            }

            // Static method call: ClassName::method()
            if ($parent instanceof StaticCall) {
                return $this->getStaticMethodHover($parent, $ast, $document);
            }

            // Property fetch: $obj->property or $this->property
            if ($parent instanceof PropertyFetch) {
                return $this->getPropertyHover($parent, $ast, $document);
            }

            // Static property fetch: ClassName::$property
            if ($parent instanceof StaticPropertyFetch) {
                return $this->getStaticPropertyHover($parent, $ast, $document);
            }

            if ($parent instanceof FuncCall) {
                return $this->getFunctionHover($node->toString(), $ast);
            }
        }

        return null;
    }

    /**
     * @param array<Stmt> $ast
     */
    private function getClassHover(Name $node, array $ast, TextDocument $document): ?string
    {
        $resolvedName = $node->getAttribute('resolvedName');
        $className = $resolvedName instanceof Name
            ? $resolvedName->toString()
            : $node->toString();

        // First look in current file
        $classNode = $this->findClassInAst($className, $ast);

        // If not found, try to locate via Composer
        if ($classNode === null && $this->classLocator !== null) {
            $filePath = $this->classLocator->locateClass($className);
            if ($filePath !== null) {
                $content = file_get_contents($filePath);
                if ($content !== false) {
                    $externalDoc = new TextDocument('file://' . $filePath, 'php', 0, $content);
                    $externalAst = $this->parser->parse($externalDoc);
                    if ($externalAst !== null) {
                        $classNode = $this->findClassInAst($className, $externalAst);
                    }
                }
            }
        }

        if ($classNode === null) {
            return null;
        }

        return $this->formatClassHover($classNode);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function findClassInAst(string $className, array $ast): Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_|null
    {
        $finder = new class ($className) extends NodeVisitorAbstract {
            public Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_|null $found = null;
            private string $namespace = '';

            public function __construct(private readonly string $className)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Stmt\Namespace_) {
                    $this->namespace = $node->name?->toString() ?? '';
                    return null;
                }

                if ($node instanceof Stmt\Class_
                    || $node instanceof Stmt\Interface_
                    || $node instanceof Stmt\Trait_
                    || $node instanceof Stmt\Enum_
                ) {
                    $name = $node->name?->toString();
                    if ($name === null) {
                        return null;
                    }
                    $fqn = $this->namespace !== '' ? $this->namespace . '\\' . $name : $name;

                    // Match by FQN or short name
                    if ($fqn === $this->className || $name === $this->className) {
                        $this->found = $node;
                        return NodeTraverser::STOP_TRAVERSAL;
                    }
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($finder);
        $traverser->traverse($ast);

        return $finder->found;
    }

    private function formatClassHover(Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_ $node): string
    {
        $parts = [];

        // Add docblock if present
        $docComment = $node->getDocComment();
        if ($docComment !== null) {
            $parts[] = $this->formatDocblock($docComment->getText());
        }

        // Add signature
        $keyword = match (true) {
            $node instanceof Stmt\Interface_ => 'interface',
            $node instanceof Stmt\Trait_ => 'trait',
            $node instanceof Stmt\Enum_ => 'enum',
            default => 'class',
        };

        $signature = $keyword . ' ' . $node->name;

        if ($node instanceof Stmt\Class_) {
            if ($node->extends !== null) {
                $signature .= ' extends ' . $node->extends->toString();
            }
            if ($node->implements !== []) {
                $implements = array_map(fn($n) => $n->toString(), $node->implements);
                $signature .= ' implements ' . implode(', ', $implements);
            }
        }

        if ($node instanceof Stmt\Interface_ && $node->extends !== []) {
            $extends = array_map(fn($n) => $n->toString(), $node->extends);
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
            $parts[] = $this->formatDocblock($docComment->getText());
        }

        // Build signature
        $params = [];
        foreach ($node->params as $param) {
            $paramStr = '';
            if ($param->type !== null) {
                $paramStr .= $this->formatType($param->type) . ' ';
            }
            $var = $param->var;
            if ($var instanceof Node\Expr\Variable && is_string($var->name)) {
                $paramStr .= '$' . $var->name;
            }
            $params[] = $paramStr;
        }

        $signature = 'function ' . $node->name->toString() . '(' . implode(', ', $params) . ')';

        if ($node->returnType !== null) {
            $signature .= ': ' . $this->formatType($node->returnType);
        }

        $parts[] = '```php' . "\n" . $signature . "\n```";

        return implode("\n\n", $parts);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function getMethodHover(MethodCall $call, array $ast, TextDocument $document): ?string
    {
        $methodName = $call->name;
        if (!$methodName instanceof Identifier) {
            return null;
        }

        $className = $this->resolveExpressionClass($call->var, $ast);
        if ($className === null) {
            return null;
        }

        return $this->getMethodHoverForClass($className, $methodName->toString(), $ast, $document);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function getStaticMethodHover(StaticCall $call, array $ast, TextDocument $document): ?string
    {
        $methodName = $call->name;
        if (!$methodName instanceof Identifier) {
            return null;
        }

        $class = $call->class;
        if (!$class instanceof Name) {
            return null;
        }

        $resolvedName = $class->getAttribute('resolvedName');
        $className = $resolvedName instanceof Name
            ? $resolvedName->toString()
            : $class->toString();

        return $this->getMethodHoverForClass($className, $methodName->toString(), $ast, $document);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function getPropertyHover(PropertyFetch $fetch, array $ast, TextDocument $document): ?string
    {
        $propertyName = $fetch->name;
        if (!$propertyName instanceof Identifier) {
            return null;
        }

        $className = $this->resolveExpressionClass($fetch->var, $ast);
        if ($className === null) {
            return null;
        }

        return $this->getPropertyHoverForClass($className, $propertyName->toString(), $ast, $document);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function getStaticPropertyHover(StaticPropertyFetch $fetch, array $ast, TextDocument $document): ?string
    {
        $propertyName = $fetch->name;
        if (!$propertyName instanceof Node\VarLikeIdentifier) {
            return null;
        }

        $class = $fetch->class;
        if (!$class instanceof Name) {
            return null;
        }

        $resolvedName = $class->getAttribute('resolvedName');
        $className = $resolvedName instanceof Name
            ? $resolvedName->toString()
            : $class->toString();

        return $this->getPropertyHoverForClass($className, $propertyName->toString(), $ast, $document);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function getMethodHoverForClass(string $className, string $methodName, array $ast, TextDocument $document): ?string
    {
        // Try to find in AST first (current file or via Composer)
        $methodNode = $this->findMethodInClass($className, $methodName, $ast, $document);
        if ($methodNode !== null) {
            return $this->formatMethodHover($methodNode);
        }

        // Fall back to reflection for built-in or autoloaded classes
        return $this->getReflectionMethodHover($className, $methodName);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function getPropertyHoverForClass(string $className, string $propertyName, array $ast, TextDocument $document): ?string
    {
        // Try to find in AST first
        $propertyNode = $this->findPropertyInClass($className, $propertyName, $ast, $document);
        if ($propertyNode !== null) {
            return $this->formatPropertyHover($propertyNode, $propertyName);
        }

        // Fall back to reflection
        return $this->getReflectionPropertyHover($className, $propertyName);
    }

    /**
     * @param array<Stmt> $ast
     */
    private function resolveExpressionClass(Node\Expr $expr, array $ast): ?string
    {
        // $this refers to the enclosing class
        if ($expr instanceof Variable && $expr->name === 'this') {
            return $this->findEnclosingClassName($expr, $ast);
        }

        // For other expressions, we'd need type inference - skip for now
        return null;
    }

    /**
     * @param array<Stmt> $ast
     */
    private function findEnclosingClassName(Node $node, array $ast): ?string
    {
        // Walk up through parent nodes to find enclosing class
        $current = $node->getAttribute('parent');
        while ($current instanceof Node) {
            if ($current instanceof Stmt\Class_ && $current->name !== null) {
                // Get the fully qualified name if available
                $namespacedName = $current->namespacedName;
                if ($namespacedName instanceof Name) {
                    return $namespacedName->toString();
                }
                return $current->name->toString();
            }
            $current = $current->getAttribute('parent');
        }
        return null;
    }

    /**
     * @param array<Stmt> $ast
     */
    private function findMethodInClass(string $className, string $methodName, array $ast, TextDocument $document): ?Stmt\ClassMethod
    {
        // First check current file
        $classNode = $this->findClassInAst($className, $ast);

        // If not found, try Composer
        if ($classNode === null && $this->classLocator !== null) {
            $filePath = $this->classLocator->locateClass($className);
            if ($filePath !== null) {
                $content = file_get_contents($filePath);
                if ($content !== false) {
                    $externalDoc = new TextDocument('file://' . $filePath, 'php', 0, $content);
                    $externalAst = $this->parser->parse($externalDoc);
                    if ($externalAst !== null) {
                        $classNode = $this->findClassInAst($className, $externalAst);
                    }
                }
            }
        }

        if ($classNode === null) {
            return null;
        }

        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof Stmt\ClassMethod && $stmt->name->toString() === $methodName) {
                return $stmt;
            }
        }

        return null;
    }

    /**
     * @param array<Stmt> $ast
     */
    private function findPropertyInClass(string $className, string $propertyName, array $ast, TextDocument $document): ?Stmt\Property
    {
        // First check current file
        $classNode = $this->findClassInAst($className, $ast);

        // If not found, try Composer
        if ($classNode === null && $this->classLocator !== null) {
            $filePath = $this->classLocator->locateClass($className);
            if ($filePath !== null) {
                $content = file_get_contents($filePath);
                if ($content !== false) {
                    $externalDoc = new TextDocument('file://' . $filePath, 'php', 0, $content);
                    $externalAst = $this->parser->parse($externalDoc);
                    if ($externalAst !== null) {
                        $classNode = $this->findClassInAst($className, $externalAst);
                    }
                }
            }
        }

        if ($classNode === null) {
            return null;
        }

        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    if ($prop->name->toString() === $propertyName) {
                        return $stmt;
                    }
                }
            }
        }

        return null;
    }

    private function formatMethodHover(Stmt\ClassMethod $method): string
    {
        $parts = [];

        $docComment = $method->getDocComment();
        if ($docComment !== null) {
            $parts[] = $this->formatDocblock($docComment->getText());
        }

        $visibility = $this->getVisibility($method);
        $static = $method->isStatic() ? 'static ' : '';

        $params = [];
        foreach ($method->params as $param) {
            $paramStr = '';
            if ($param->type !== null) {
                $paramStr .= $this->formatType($param->type) . ' ';
            }
            $var = $param->var;
            if ($var instanceof Variable && is_string($var->name)) {
                $paramStr .= '$' . $var->name;
            }
            $params[] = $paramStr;
        }

        $signature = $visibility . $static . 'function ' . $method->name->toString() . '(' . implode(', ', $params) . ')';

        if ($method->returnType !== null) {
            $signature .= ': ' . $this->formatType($method->returnType);
        }

        $parts[] = '```php' . "\n" . $signature . "\n```";

        return implode("\n\n", $parts);
    }

    private function formatPropertyHover(Stmt\Property $property, string $propertyName): string
    {
        $parts = [];

        $docComment = $property->getDocComment();
        if ($docComment !== null) {
            $parts[] = $this->formatDocblock($docComment->getText());
        }

        $visibility = $this->getPropertyVisibility($property);
        $static = $property->isStatic() ? 'static ' : '';
        $readonly = $property->isReadonly() ? 'readonly ' : '';

        $type = '';
        if ($property->type !== null) {
            $type = $this->formatType($property->type) . ' ';
        }

        $signature = $visibility . $static . $readonly . $type . '$' . $propertyName;

        $parts[] = '```php' . "\n" . $signature . "\n```";

        return implode("\n\n", $parts);
    }

    private function getVisibility(Stmt\ClassMethod $method): string
    {
        if ($method->isPrivate()) {
            return 'private ';
        }
        if ($method->isProtected()) {
            return 'protected ';
        }
        return 'public ';
    }

    private function getPropertyVisibility(Stmt\Property $property): string
    {
        if ($property->isPrivate()) {
            return 'private ';
        }
        if ($property->isProtected()) {
            return 'protected ';
        }
        return 'public ';
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

    private function getReflectionMethodHover(string $className, string $methodName): ?string
    {
        try {
            if (!class_exists($className) && !interface_exists($className) && !trait_exists($className)) {
                return null;
            }
            $classReflection = new ReflectionClass($className);
            if (!$classReflection->hasMethod($methodName)) {
                return null;
            }
            $reflection = $classReflection->getMethod($methodName);
            return $this->formatReflectionMethod($reflection);
        } catch (ReflectionException) {
            return null;
        }
    }

    private function getReflectionPropertyHover(string $className, string $propertyName): ?string
    {
        try {
            if (!class_exists($className) && !interface_exists($className) && !trait_exists($className)) {
                return null;
            }
            $classReflection = new ReflectionClass($className);
            if (!$classReflection->hasProperty($propertyName)) {
                return null;
            }
            $reflection = $classReflection->getProperty($propertyName);
            return $this->formatReflectionProperty($reflection);
        } catch (ReflectionException) {
            return null;
        }
    }

    private function formatReflectionFunction(ReflectionFunction $func): string
    {
        $parts = [];

        $docComment = $func->getDocComment();
        if ($docComment !== false) {
            $parts[] = $this->formatDocblock($docComment);
        }

        $params = [];
        foreach ($func->getParameters() as $param) {
            $paramStr = '';
            $type = $param->getType();
            if ($type !== null) {
                $paramStr .= $this->formatReflectionType($type) . ' ';
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
            $signature .= ': ' . $this->formatReflectionType($returnType);
        }

        $parts[] = '```php' . "\n" . $signature . "\n```";

        return implode("\n\n", $parts);
    }

    private function formatReflectionMethod(ReflectionMethod $method): string
    {
        $parts = [];

        $docComment = $method->getDocComment();
        if ($docComment !== false) {
            $parts[] = $this->formatDocblock($docComment);
        }

        $visibility = match (true) {
            $method->isPrivate() => 'private ',
            $method->isProtected() => 'protected ',
            default => 'public ',
        };
        $static = $method->isStatic() ? 'static ' : '';

        $params = [];
        foreach ($method->getParameters() as $param) {
            $paramStr = '';
            $type = $param->getType();
            if ($type !== null) {
                $paramStr .= $this->formatReflectionType($type) . ' ';
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

        $signature = $visibility . $static . 'function ' . $method->getName() . '(' . implode(', ', $params) . ')';

        $returnType = $method->getReturnType();
        if ($returnType !== null) {
            $signature .= ': ' . $this->formatReflectionType($returnType);
        }

        $parts[] = '```php' . "\n" . $signature . "\n```";

        return implode("\n\n", $parts);
    }

    private function formatReflectionProperty(ReflectionProperty $property): string
    {
        $parts = [];

        $docComment = $property->getDocComment();
        if ($docComment !== false) {
            $parts[] = $this->formatDocblock($docComment);
        }

        $visibility = match (true) {
            $property->isPrivate() => 'private ',
            $property->isProtected() => 'protected ',
            default => 'public ',
        };
        $static = $property->isStatic() ? 'static ' : '';
        $readonly = $property->isReadOnly() ? 'readonly ' : '';

        $type = $property->getType();
        $typeStr = $type !== null ? $this->formatReflectionType($type) . ' ' : '';

        $signature = $visibility . $static . $readonly . $typeStr . '$' . $property->getName();

        $parts[] = '```php' . "\n" . $signature . "\n```";

        return implode("\n\n", $parts);
    }

    private function formatReflectionType(\ReflectionType $type): string
    {
        if ($type instanceof \ReflectionNamedType) {
            $name = $type->getName();
            return $type->allowsNull() && $name !== 'null' && $name !== 'mixed' ? '?' . $name : $name;
        }
        if ($type instanceof \ReflectionUnionType) {
            return implode('|', array_map(fn($t) => $this->formatReflectionType($t), $type->getTypes()));
        }
        if ($type instanceof \ReflectionIntersectionType) {
            return implode('&', array_map(fn($t) => $this->formatReflectionType($t), $type->getTypes()));
        }
        return (string) $type;
    }

    private function formatDocblock(string $docblock): string
    {
        // Strip /** and */ and clean up
        $lines = explode("\n", $docblock);
        $cleaned = [];

        foreach ($lines as $line) {
            $line = trim($line);
            $line = preg_replace('/^\/\*\*\s*/', '', $line) ?? '';
            $line = preg_replace('/^\*\/\s*$/', '', $line) ?? '';
            $line = preg_replace('/^\*\s?/', '', $line) ?? '';

            if ($line !== '') {
                $cleaned[] = $line;
            }
        }

        return implode("\n", $cleaned);
    }

    private function formatType(Node $type): string
    {
        if ($type instanceof Name) {
            return $type->toString();
        }
        if ($type instanceof Identifier) {
            return $type->toString();
        }
        if ($type instanceof Node\NullableType) {
            return '?' . $this->formatType($type->type);
        }
        if ($type instanceof Node\UnionType) {
            return implode('|', array_map(fn($t) => $this->formatType($t), $type->types));
        }
        if ($type instanceof Node\IntersectionType) {
            return implode('&', array_map(fn($t) => $this->formatType($t), $type->types));
        }
        return '';
    }
}
