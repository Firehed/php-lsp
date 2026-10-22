<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Parser\SyntaxSource;

use Firehed\PhpLsp\Parser\ParsedDocument;
use PhpParser\ErrorHandler;
use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\CallLike;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\Token;

/**
 * The {@see CodeParserInterface} backed by php-parser: the one class that names
 * {@see Parser}.
 */
final class PhpParserCodeParser implements CodeParserInterface
{
    private readonly Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    public function parse(string $code): ParseAttempt
    {
        $errors = new ErrorHandler\Collecting();
        $tree = $this->parser->parse($code, $errors) ?? [];
        $tokens = $this->parser->getTokens();
        $calls = (new NodeFinder())->find(
            $tree,
            static fn (Node $node): bool => $node instanceof CallLike || $node instanceof Attribute,
        );
        foreach ($calls as $call) {
            assert($call instanceof CallLike || $call instanceof Attribute);
            $call->setAttribute(ParsedDocument::ARGUMENT_SEPARATORS, self::argumentSeparators($call, $tokens));
        }
        return new ParseAttempt($tree, array_values($tokens), array_values($errors->getErrors()));
    }

    /**
     * A call's own commas sit only in the gaps between its arguments, or after
     * the last one before the `)` that closes the list (the call's last token,
     * when recovery left it unclosed), so only those gaps are scanned.
     *
     * @param array<Token> $tokens
     * @return list<int>
     */
    private static function argumentSeparators(CallLike|Attribute $call, array $tokens): array
    {
        $args = $call instanceof Attribute ? $call->args : $call->getRawArgs();
        $separators = [];
        foreach ($args as $i => $arg) {
            $next = $args[$i + 1] ?? null;
            $gapEnd = $next === null ? $call->getEndTokenPos() : $next->getStartTokenPos() - 1;
            for ($t = $arg->getEndTokenPos() + 1; $t <= $gapEnd; $t++) {
                if ($next === null && $tokens[$t]->is(')')) {
                    break;
                }
                if ($tokens[$t]->is(',')) {
                    $separators[] = $tokens[$t]->pos;
                }
            }
        }
        return $separators;
    }
}
