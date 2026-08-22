<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * The RFC 1 §8.1 rules read the class a reference names, so a name computed at
 * runtime is a door around every one of them: `new $type` constructs a Type
 * outside the factory, `$value instanceof $wanted` inspects a concrete kind,
 * and `$value::class` asks the runtime what a value is, all unreported.
 *
 * A class is therefore named literally. There is no allowlist: the pre-existing
 * uses are in the baseline, to be drained rather than promoted.
 *
 * @implements Rule<Node>
 */
final class DynamicClassReferenceRule implements Rule
{
    /**
     * Adding an entry loosens (human only). See
     * docs/architecture/enforcement-edits.md.
     *
     * @var list<string>
     */
    private const array ALLOWED_FILES = [];

    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $form = $this->computedClassForm($node);
        if ($form === null) {
            return [];
        }

        if (ConfinedFile::isExempt($scope->getFile(), self::ALLOWED_FILES)) {
            return [];
        }

        $message = sprintf(
            '%s on a computed class name; name the class literally (RFC 1 §4.5).',
            $form,
        );

        return [
            RuleErrorBuilder::message($message)
                ->identifier('phpLsp.dynamicClassReference')
                ->build(),
        ];
    }

    /**
     * The syntactic form this node is, when the class it names is an
     * expression. An anonymous class declares its class rather than naming one.
     */
    private function computedClassForm(Node $node): ?string
    {
        if ($node instanceof New_) {
            return $this->formWhenComputed($node->class, 'new');
        }
        if ($node instanceof Instanceof_) {
            return $this->formWhenComputed($node->class, 'instanceof');
        }
        if ($node instanceof ClassConstFetch) {
            return $this->formWhenComputed($node->class, 'class constant fetch');
        }
        if ($node instanceof StaticCall) {
            return $this->formWhenComputed($node->class, 'static call');
        }
        if ($node instanceof StaticPropertyFetch) {
            return $this->formWhenComputed($node->class, 'static property fetch');
        }

        return null;
    }

    private function formWhenComputed(Node $class, string $form): ?string
    {
        if ($class instanceof Name || $class instanceof Class_) {
            return null;
        }

        return $form;
    }
}
