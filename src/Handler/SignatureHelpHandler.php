<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Index\ComposerClassLocator;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\TypeInference\TypeResolverInterface;
use Firehed\PhpLsp\Utility\ClassFinder;
use Firehed\PhpLsp\Utility\DocblockParser;
use Firehed\PhpLsp\Utility\ReflectionHelper;
use Firehed\PhpLsp\Utility\ScopeFinder;
use Firehed\PhpLsp\Utility\TypeFormatter;
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
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @phpstan-type ParameterInfo array{label: string, documentation?: string}
 * @phpstan-type SignatureInfo array{
 *   label: string,
 *   documentation?: string,
 *   parameters?: list<ParameterInfo>,
 * }
 */
final class SignatureHelpHandler implements HandlerInterface
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly ParserService $parser,
        private readonly ?ComposerClassLocator $classLocator,
        private readonly ?TypeResolverInterface $typeResolver = null,
    ) {
    }

    public function supports(string $method): bool
    {
        return $method === 'textDocument/signatureHelp';
    }

    /**
     * @return array{signatures: list<SignatureInfo>, activeSignature: int, activeParameter: int}|null
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
                if (
                    !$node instanceof FuncCall
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
     * @return SignatureInfo|null
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
     * @return SignatureInfo|null
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
     * @return SignatureInfo|null
     */
    private function getMethodSignature(MethodCall $call, array $ast, TextDocument $document): ?array
    {
        $methodName = $call->name;
        if (!$methodName instanceof Identifier) {
            return null;
        }

        $className = $this->resolveExpressionClass($call->var, $ast);
        if ($className === null) {
            return null;
        }

        return $this->getMethodSignatureForClass($className, $methodName->toString(), $ast, $document);
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

        // Use type resolver for other expressions
        if ($this->typeResolver !== null) {
            $scope = ScopeFinder::findEnclosingScope($expr, $ast);
            if ($scope !== null) {
                return $this->typeResolver->resolveExpressionType($expr, $scope, $ast);
            }
        }

        return null;
    }

    /**
     * @param array<Stmt> $ast
     * @return SignatureInfo|null
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
     * @return SignatureInfo|null
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
     * @return SignatureInfo|null
     */
    private function getMethodSignatureForClass(
        string $className,
        string $methodName,
        array $ast,
        TextDocument $document,
    ): ?array {
        // Try to find in AST first
        $methodNode = $this->findMethodInClass($className, $methodName, $ast, $document);
        if ($methodNode !== null) {
            return $this->formatMethodNodeSignature($methodNode);
        }

        // Fall back to reflection
        $classReflection = ReflectionHelper::getClass($className);
        if ($classReflection === null || !$classReflection->hasMethod($methodName)) {
            return null;
        }
        $reflection = $classReflection->getMethod($methodName);
        return $this->formatReflectionSignature($reflection);
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
    private function findMethodInClass(
        string $className,
        string $methodName,
        array $ast,
        TextDocument $document,
    ): ?Stmt\ClassMethod {
        $classNode = ClassFinder::findWithLocator($className, $ast, $this->classLocator, $this->parser);
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
     * @return SignatureInfo
     */
    private function formatFunctionNodeSignature(Stmt\Function_ $func): array
    {
        $params = [];
        $paramLabels = [];

        foreach ($func->params as $param) {
            $paramStr = '';
            if ($param->type !== null) {
                $paramStr .= TypeFormatter::formatNode($param->type) . ' ';
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
            $label .= ': ' . TypeFormatter::formatNode($func->returnType);
        }

        $result = [
            'label' => $label,
            'parameters' => $params,
        ];

        $docComment = $func->getDocComment();
        if ($docComment !== null) {
            $result['documentation'] = DocblockParser::extractDescription($docComment->getText());
        }

        return $result;
    }

    /**
     * @return SignatureInfo
     */
    private function formatMethodNodeSignature(Stmt\ClassMethod $method): array
    {
        $params = [];
        $paramLabels = [];

        foreach ($method->params as $param) {
            $paramStr = '';
            if ($param->type !== null) {
                $paramStr .= TypeFormatter::formatNode($param->type) . ' ';
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
            $label .= ': ' . TypeFormatter::formatNode($method->returnType);
        }

        $result = [
            'label' => $label,
            'parameters' => $params,
        ];

        $docComment = $method->getDocComment();
        if ($docComment !== null) {
            $result['documentation'] = DocblockParser::extractDescription($docComment->getText());
        }

        return $result;
    }

    /**
     * @return SignatureInfo
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
            $label .= ': ' . TypeFormatter::formatReflection($returnType);
        }

        $result = [
            'label' => $label,
            'parameters' => $params,
        ];

        $docComment = $func->getDocComment();
        if ($docComment !== false) {
            $result['documentation'] = DocblockParser::extractDescription($docComment);
        }

        return $result;
    }

    private function formatReflectionParameter(ReflectionParameter $param): string
    {
        $paramStr = '';
        $type = $param->getType();
        if ($type !== null) {
            $paramStr .= TypeFormatter::formatReflection($type) . ' ';
        }
        if ($param->isVariadic()) {
            $paramStr .= '...';
        }
        $paramStr .= '$' . $param->getName();

        return $paramStr;
    }
}
