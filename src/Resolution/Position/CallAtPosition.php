<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution\Position;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ByteOffset;
use Firehed\PhpLsp\Resolution\CallContext;
use Firehed\PhpLsp\Resolution\ExpressionResolverInterface;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ConstFetch;

final class CallAtPosition implements CallAtPositionInterface
{
    public function __construct(
        private readonly PositionLocatorInterface $locator,
        private readonly ExpressionResolverInterface $expressions,
    ) {
    }

    public function callAt(TextDocument $document, ByteOffset $offset): ?CallContext
    {
        $located = $this->locator->locate($document, $offset);
        $site = $located->call;
        if ($site === null) {
            return null;
        }
        $callable = $this->expressions->resolveCallable($site->call, $located->parsed);
        if ($callable === null) {
            return null;
        }
        return new CallContext(
            $callable,
            $site->argumentIndex,
            $site->usedNames,
            $site->positionalCount,
            self::inValue($site->call->args[$site->argumentIndex] ?? null, $offset->value),
        );
    }

    /**
     * Whether the argument being typed is past the point where a name could
     * still be written: a named one once the position is past its name and the
     * character after it (the colon, as `name:` is written); a positional one
     * once it has begun and is not a bare word, which may yet become a name.
     */
    private static function inValue(?Node $typing, int $offset): bool
    {
        if (!$typing instanceof Arg) {
            return false;
        }
        if ($typing->name !== null) {
            return $offset > $typing->name->getEndFilePos() + 1;
        }
        $value = $typing->value;
        // Written as one word: the name spans only its last part, however it
        // resolved. A spread or by-reference argument is never a name.
        $bareWord = $value instanceof ConstFetch
            && !$typing->unpack
            && !$typing->byRef
            && $value->name->getEndFilePos() - $value->name->getStartFilePos() + 1 === strlen($value->name->getLast());
        return !$bareWord && $offset > $typing->getStartFilePos();
    }
}
