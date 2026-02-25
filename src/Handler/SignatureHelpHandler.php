<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Index\ComposerClassLocator;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\Message;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;

final class SignatureHelpHandler implements HandlerInterface
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly ParserService $parser,
        private readonly ?ComposerClassLocator $classLocator,
    ) {
    }

    public function supports(string $method): bool
    {
        return $method === 'textDocument/signatureHelp';
    }

    /**
     * @return array{signatures: list<array{label: string, documentation?: string, parameters?: list<array{label: string, documentation?: string}>}>, activeSignature: int, activeParameter: int}|null
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

        // Find the call expression containing this position
        $callInfo = $this->findCallAtPosition($ast, $offset);
        if ($callInfo === null) {
            return null;
        }

        [$callNode, $activeParameter] = $callInfo;

        $signature = $this->getSignature($callNode, $ast, $document);
        if ($signature === null) {
            return null;
        }

        return [
            'signatures' => [$signature],
            'activeSignature' => 0,
            'activeParameter' => $activeParameter,
        ];
    }

    /**
     * Find the function/method call at the given position and determine active parameter.
     *
     * @param array<Stmt> $ast
     * @return array{0: FuncCall|MethodCall|StaticCall|New_, 1: int}|null
     */
    private function findCallAtPosition(array $ast, int $offset): ?array
    {
        $finder = new class ($offset) extends NodeVisitorAbstract {
            /** @var FuncCall|MethodCall|StaticCall|New_|null */
            public ?Node $found = null;
            public int $activeParameter = 0;

            public function __construct(private readonly int $offset)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if (!$node instanceof FuncCall
                    && !$node instanceof MethodCall
                    && !$node instanceof StaticCall
                    && !$node instanceof New_
                ) {
                    return null;
                }

                $startPos = $node->getStartFilePos();
                $endPos = $node->getEndFilePos();

                // Check if cursor is within this call
                if ($startPos <= $this->offset && $this->offset <= $endPos) {
                    // Determine which parameter the cursor is in
                    $activeParam = 0;
                    foreach ($node->args as $i => $arg) {
                        $argEnd = $arg->getEndFilePos();
                        if ($this->offset > $argEnd) {
                            $activeParam = $i + 1;
                        }
                    }

                    // Keep the most specific (innermost) call
                    $this->found = $node;
                    $this->activeParameter = $activeParam;
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

        return [$finder->found, $finder->activeParameter];
    }

    /**
     * @param FuncCall|MethodCall|StaticCall|New_ $call
     * @param array<Stmt> $ast
     * @return array{label: string, documentation?: string, parameters?: list<array{label: string, documentation?: string}>}|null
     */
    private function getSignature(Node $call, array $ast, TextDocument $document): ?array
    {
        if ($call instanceof FuncCall) {
            return $this->getFunctionSignature($call, $ast);
        }

        if ($call instanceof MethodCall) {
            return $this->getMethodSignature($call, $ast, $document);
        }

        if ($call instanceof StaticCall) {
            return $this->getStaticMethodSignature($call, $ast, $document);
        }

        // Must be New_ at this point based on the type hint
        return $this->getConstructorSignature($call, $ast, $document);
    }

    /**
     * @param array<Stmt> $ast
     * @return array{label: string, documentation?: string, parameters?: list<array{label: string, documentation?: string}>}|null
     */
    private function getFunctionSignature(FuncCall $call, array $ast): ?array
    {
        $name = $call->name;
        if (!$name instanceof Name) {
            return null;
        }

        $functionName = $name->toString();

        // Try user-defined function first
        $funcNode = $this->findFunctionInAst($functionName, $ast);
        if ($funcNode !== null) {
            return $this->formatFunctionNodeSignature($funcNode);
        }

        // Fall back to built-in function
        try {
            $reflection = new ReflectionFunction($functionName);
            return $this->formatReflectionSignature($reflection);
        } catch (ReflectionException) {
            return null;
        }
    }

    /**
     * @param array<Stmt> $ast
     * @return array{label: string, documentation?: string, parameters?: list<array{label: string, documentation?: string}>}|null
     */
    private function getMethodSignature(MethodCall $call, array $ast, TextDocument $document): ?array
    {
        $methodName = $call->name;
        if (!$methodName instanceof Identifier) {
            return null;
        }

        // Only support $this for now
        $var = $call->var;
        if (!$var instanceof Variable || $var->name !== 'this') {
            return null;
        }

        $className = $this->findEnclosingClassName($call, $ast);
        if ($className === null) {
            return null;
        }

        return $this->getMethodSignatureForClass($className, $methodName->toString(), $ast, $document);
    }

    /**
     * @param array<Stmt> $ast
     * @return array{label: string, documentation?: string, parameters?: list<array{label: string, documentation?: string}>}|null
     */
    private function getStaticMethodSignature(StaticCall $call, array $ast, TextDocument $document): ?array
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

        // Handle self/static/parent
        if ($className === 'self' || $className === 'static' || $className === 'parent') {
            $enclosingClass = $this->findEnclosingClassName($call, $ast);
            if ($enclosingClass === null) {
                return null;
            }
            $className = $enclosingClass;
        }

        return $this->getMethodSignatureForClass($className, $methodName->toString(), $ast, $document);
    }

    /**
     * @param array<Stmt> $ast
     * @return array{label: string, documentation?: string, parameters?: list<array{label: string, documentation?: string}>}|null
     */
    private function getConstructorSignature(New_ $call, array $ast, TextDocument $document): ?array
    {
        $class = $call->class;
        if (!$class instanceof Name) {
            return null;
        }

        $resolvedName = $class->getAttribute('resolvedName');
        $className = $resolvedName instanceof Name
            ? $resolvedName->toString()
            : $class->toString();

        return $this->getMethodSignatureForClass($className, '__construct', $ast, $document);
    }

    /**
     * @param array<Stmt> $ast
     * @return array{label: string, documentation?: string, parameters?: list<array{label: string, documentation?: string}>}|null
     */
    private function getMethodSignatureForClass(string $className, string $methodName, array $ast, TextDocument $document): ?array
    {
        // Try to find in AST first
        $methodNode = $this->findMethodInClass($className, $methodName, $ast, $document);
        if ($methodNode !== null) {
            return $this->formatMethodNodeSignature($methodNode);
        }

        // Fall back to reflection
        try {
            if (!class_exists($className) && !interface_exists($className) && !trait_exists($className)) {
                return null;
            }
            $classReflection = new ReflectionClass($className);
            if (!$classReflection->hasMethod($methodName)) {
                return null;
            }
            $reflection = $classReflection->getMethod($methodName);
            return $this->formatReflectionSignature($reflection);
        } catch (ReflectionException) {
            return null;
        }
    }

    /**
     * @param array<Stmt> $ast
     */
    private function findFunctionInAst(string $functionName, array $ast): ?Stmt\Function_
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

        return $finder->found;
    }

    /**
     * @param array<Stmt> $ast
     */
    private function findEnclosingClassName(Node $node, array $ast): ?string
    {
        $current = $node->getAttribute('parent');
        while ($current instanceof Node) {
            if ($current instanceof Stmt\Class_ && $current->name !== null) {
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
        $classNode = $this->findClassInAst($className, $ast);

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
    private function findClassInAst(string $className, array $ast): Stmt\Class_|Stmt\Interface_|Stmt\Trait_|null
    {
        $finder = new class ($className) extends NodeVisitorAbstract {
            public Stmt\Class_|Stmt\Interface_|Stmt\Trait_|null $found = null;
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
                ) {
                    $name = $node->name?->toString();
                    if ($name === null) {
                        return null;
                    }
                    $fqn = $this->namespace !== '' ? $this->namespace . '\\' . $name : $name;

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

    /**
     * @return array{label: string, documentation?: string, parameters?: list<array{label: string, documentation?: string}>}
     */
    private function formatFunctionNodeSignature(Stmt\Function_ $func): array
    {
        $params = [];
        $paramLabels = [];

        foreach ($func->params as $param) {
            $paramStr = '';
            if ($param->type !== null) {
                $paramStr .= $this->formatType($param->type) . ' ';
            }
            $var = $param->var;
            if ($var instanceof Variable && is_string($var->name)) {
                $paramStr .= '$' . $var->name;
            }
            $paramLabels[] = $paramStr;
            $params[] = ['label' => $paramStr];
        }

        $label = 'function ' . $func->name->toString() . '(' . implode(', ', $paramLabels) . ')';
        if ($func->returnType !== null) {
            $label .= ': ' . $this->formatType($func->returnType);
        }

        $result = [
            'label' => $label,
            'parameters' => $params,
        ];

        $docComment = $func->getDocComment();
        if ($docComment !== null) {
            $result['documentation'] = $this->extractDocDescription($docComment->getText());
        }

        return $result;
    }

    /**
     * @return array{label: string, documentation?: string, parameters?: list<array{label: string, documentation?: string}>}
     */
    private function formatMethodNodeSignature(Stmt\ClassMethod $method): array
    {
        $params = [];
        $paramLabels = [];

        foreach ($method->params as $param) {
            $paramStr = '';
            if ($param->type !== null) {
                $paramStr .= $this->formatType($param->type) . ' ';
            }
            $var = $param->var;
            if ($var instanceof Variable && is_string($var->name)) {
                $paramStr .= '$' . $var->name;
            }
            $paramLabels[] = $paramStr;
            $params[] = ['label' => $paramStr];
        }

        $label = $method->name->toString() . '(' . implode(', ', $paramLabels) . ')';
        if ($method->returnType !== null) {
            $label .= ': ' . $this->formatType($method->returnType);
        }

        $result = [
            'label' => $label,
            'parameters' => $params,
        ];

        $docComment = $method->getDocComment();
        if ($docComment !== null) {
            $result['documentation'] = $this->extractDocDescription($docComment->getText());
        }

        return $result;
    }

    /**
     * @return array{label: string, documentation?: string, parameters?: list<array{label: string, documentation?: string}>}
     */
    private function formatReflectionSignature(ReflectionFunctionAbstract $func): array
    {
        $params = [];
        $paramLabels = [];

        foreach ($func->getParameters() as $param) {
            $paramStr = $this->formatReflectionParameter($param);
            $paramLabels[] = $paramStr;
            $params[] = ['label' => $paramStr];
        }

        $name = $func instanceof ReflectionMethod
            ? $func->getName()
            : 'function ' . $func->getName();

        $label = $name . '(' . implode(', ', $paramLabels) . ')';

        $returnType = $func->getReturnType();
        if ($returnType !== null) {
            $label .= ': ' . $this->formatReflectionType($returnType);
        }

        $result = [
            'label' => $label,
            'parameters' => $params,
        ];

        $docComment = $func->getDocComment();
        if ($docComment !== false) {
            $result['documentation'] = $this->extractDocDescription($docComment);
        }

        return $result;
    }

    private function formatReflectionParameter(ReflectionParameter $param): string
    {
        $paramStr = '';
        $type = $param->getType();
        if ($type !== null) {
            $paramStr .= $this->formatReflectionType($type) . ' ';
        }
        if ($param->isVariadic()) {
            $paramStr .= '...';
        }
        $paramStr .= '$' . $param->getName();

        return $paramStr;
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

    private function extractDocDescription(string $docblock): string
    {
        $lines = explode("\n", $docblock);
        $description = [];

        foreach ($lines as $line) {
            $line = trim($line);
            $line = preg_replace('/^\/\*\*\s*/', '', $line) ?? '';
            $line = preg_replace('/^\*\/\s*$/', '', $line) ?? '';
            $line = preg_replace('/^\*\s?/', '', $line) ?? '';

            // Stop at first @tag
            if (str_starts_with($line, '@')) {
                break;
            }

            if ($line !== '') {
                $description[] = $line;
            }
        }

        return implode("\n", $description);
    }
}
