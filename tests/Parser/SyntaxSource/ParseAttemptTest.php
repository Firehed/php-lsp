<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Parser\SyntaxSource;

use Firehed\PhpLsp\Parser\SyntaxSource\ParseAttempt;
use PhpParser\Error;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Token;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ParseAttempt::class)]
final class ParseAttemptTest extends TestCase
{
    public function testConstruction(): void
    {
        $tree = [new Nop()];
        $tokens = [new Token(T_OPEN_TAG, '<?php ', 1, 0)];
        $errors = [new Error('Syntax error')];

        $attempt = new ParseAttempt($tree, $tokens, $errors);

        self::assertSame($tree, $attempt->tree, 'the tree it read');
        self::assertSame($tokens, $attempt->tokens, 'the tokens the code lexed to');
        self::assertSame($errors, $attempt->errors, 'the errors reported');
    }
}
