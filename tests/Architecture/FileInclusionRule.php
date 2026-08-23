<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

use PhpParser\Node;
use PhpParser\Node\Expr\Include_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * The RFC 1 §8.1 filesystem confinement, for the one read the language spells
 * as a keyword: `include` and `require` open and read a file, and a
 * disallowed-calls entry can only name functions.
 *
 * @implements Rule<Include_>
 */
final class FileInclusionRule implements Rule
{
    /**
     * Adding an entry loosens (human only). Removing one tightens. See
     * docs/architecture/enforcement-edits.md.
     */
    private const array ALLOWED_FILES = [
        'src/Index/ComposerAutoloadMap.php', // Composer writes its maps as PHP
    ];

    private const array KEYWORDS = [
        Include_::TYPE_INCLUDE => 'include',
        Include_::TYPE_INCLUDE_ONCE => 'include_once',
        Include_::TYPE_REQUIRE => 'require',
        Include_::TYPE_REQUIRE_ONCE => 'require_once',
    ];

    public function getNodeType(): string
    {
        return Include_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (ConfinedFile::isExempt($scope->getFile(), self::ALLOWED_FILES)) {
            return [];
        }

        $message = sprintf(
            '%s reads a file; filesystem access is confined (RFC 1 §8.1).',
            self::KEYWORDS[$node->type],
        );

        return [
            RuleErrorBuilder::message($message)
                ->identifier('phpLsp.fileInclusion')
                ->build(),
        ];
    }
}
