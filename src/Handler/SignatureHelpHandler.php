<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Handler;

use Firehed\PhpLsp\Document\DocumentManager;
use Firehed\PhpLsp\Domain\ClassName;
use Firehed\PhpLsp\Domain\FunctionInfo;
use Firehed\PhpLsp\Domain\MethodInfo;
use Firehed\PhpLsp\Domain\MethodName;
use Firehed\PhpLsp\Domain\Visibility;
use Firehed\PhpLsp\Parser\ParserService;
use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Repository\MemberResolver;
use Firehed\PhpLsp\TypeInference\TypeResolverInterface;
use Firehed\PhpLsp\Utility\DocblockParser;
use Firehed\PhpLsp\Utility\MemberAccessResolver;
use Firehed\PhpLsp\Utility\ScopeFinder;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use ReflectionException;
use ReflectionFunction;

/**
 * @phpstan-type ParameterInfoShape array{label: string, documentation?: string}
 * @phpstan-type SignatureInfo array{
 *   label: string,
 *   documentation?: string,
 *   parameters?: list<ParameterInfoShape>,
 * }
 */
final class SignatureHelpHandler implements HandlerInterface
{
    private readonly MemberAccessResolver $memberAccessResolver;

    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly ParserService $parser,
        private readonly MemberResolver $memberResolver,
        TypeResolverInterface $typeResolver,
    ) {
        $this->memberAccessResolver = new MemberAccessResolver($typeResolver);
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

        $signature = $this->getSignature($callNode, $ast);
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
     * @return array{0: FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_, 1: int}|null
     */
    private function findCallAtPosition(array $ast, int $offset): ?array
    {
        $finder = new class ($offset) extends NodeVisitorAbstract {
            /** @var FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_|null */
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
                    && !$node instanceof NullsafeMethodCall
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
     * @param FuncCall|MethodCall|NullsafeMethodCall|StaticCall|New_ $call
     * @param array<Stmt> $ast
     * @return SignatureInfo|null
     */
    private function getSignature(Node $call, array $ast): ?array
    {
        if ($call instanceof FuncCall) {
            return $this->getFunctionSignature($call, $ast);
        }

        if ($call instanceof MethodCall || $call instanceof NullsafeMethodCall) {
            return $this->getMethodSignature($call, $ast);
        }

        if ($call instanceof StaticCall) {
            return $this->getStaticMethodSignature($call);
        }

        // Must be New_ at this point based on the type hint
        return $this->getConstructorSignature($call);
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
        $funcNode = ScopeFinder::findFunction($functionName, $ast);
        if ($funcNode !== null) {
            return $this->formatFunctionNodeSignature($funcNode);
        }

        // Fall back to built-in function
        try {
            $funcInfo = FunctionInfo::fromReflection(new ReflectionFunction($functionName));
            return $this->formatFunctionInfoSignature($funcInfo);
        } catch (ReflectionException) {
            return null;
        }
    }

    /**
     * @param array<Stmt> $ast
     * @return SignatureInfo|null
     */
    private function getMethodSignature(MethodCall|NullsafeMethodCall $call, array $ast): ?array
    {
        $methodName = $call->name;
        if (!$methodName instanceof Identifier) {
            return null;
        }

        $className = $this->memberAccessResolver->resolveObjectClassName($call->var, $ast);
        if ($className === null) {
            return null;
        }

        return $this->getMethodSignatureForClass($className->fqn, $methodName->toString());
    }

    /**
     * @return SignatureInfo|null
     */
    private function getStaticMethodSignature(StaticCall $call): ?array
    {
        $methodName = $call->name;
        if (!$methodName instanceof Identifier) {
            return null;
        }

        $class = $call->class;
        if (!$class instanceof Name) {
            return null;
        }

        $rawName = $class->toString();

        // Handle self/static/parent
        if ($rawName === 'self' || $rawName === 'static' || $rawName === 'parent') {
            $className = ScopeFinder::findEnclosingClassName($call);
            if ($className === null) {
                return null;
            }
        } else {
            $className = ScopeFinder::resolveClassName($class);
        }

        return $this->getMethodSignatureForClass($className, $methodName->toString());
    }

    /**
     * @return SignatureInfo|null
     */
    private function getConstructorSignature(New_ $call): ?array
    {
        $class = $call->class;
        if (!$class instanceof Name) {
            return null;
        }

        $className = ScopeFinder::resolveClassName($class);

        return $this->getMethodSignatureForClass($className, '__construct');
    }

    /**
     * @param class-string $classNameStr
     * @return SignatureInfo|null
     */
    private function getMethodSignatureForClass(
        string $classNameStr,
        string $methodNameStr,
    ): ?array {
        $className = new ClassName($classNameStr);
        $methodName = new MethodName($methodNameStr);

        $methodInfo = $this->memberResolver->findMethod($className, $methodName, Visibility::Private);
        if ($methodInfo !== null) {
            return $this->formatMethodInfoSignature($methodInfo);
        }

        return null;
    }


    /**
     * @return SignatureInfo
     */
    private function formatFunctionNodeSignature(Stmt\Function_ $func): array
    {
        $funcInfo = FunctionInfo::fromNode($func);
        return $this->formatFunctionInfoSignature($funcInfo);
    }

    /**
     * @return SignatureInfo
     */
    private function formatFunctionInfoSignature(FunctionInfo $func): array
    {
        $params = array_map(
            fn($p) => ['label' => $p->format()],
            $func->parameters,
        );

        $result = [
            'label' => $func->format(),
            'parameters' => $params,
        ];

        if ($func->docblock !== null) {
            $result['documentation'] = DocblockParser::extractDescription($func->docblock);
        }

        return $result;
    }

    /**
     * @return SignatureInfo
     */
    private function formatMethodInfoSignature(MethodInfo $method): array
    {
        $params = array_map(
            fn($p) => ['label' => $p->format()],
            $method->parameters,
        );

        $result = [
            'label' => $method->format(),
            'parameters' => $params,
        ];

        if ($method->docblock !== null) {
            $result['documentation'] = DocblockParser::extractDescription($method->docblock);
        }

        return $result;
    }
}
