<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Architecture;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<FileInclusionRule>
 */
class FileInclusionRuleTest extends RuleTestCase
{
    public function testEveryInclusionFormIsReported(): void
    {
        $this->analyse(
            [__DIR__ . '/data/includes-a-file.php'],
            [
                ['require reads a file; filesystem access is confined.', 15],
                ['require_once reads a file; filesystem access is confined.', 20],
                ['include reads a file; filesystem access is confined.', 25],
                ['include_once reads a file; filesystem access is confined.', 30],
            ],
        );
    }

    public function testTheComposerMetadataReaderIsAllowed(): void
    {
        $this->analyse([__DIR__ . '/../../src/Domain/ComposerAutoloadMap.php'], []);
    }

    protected function getRule(): Rule
    {
        return new FileInclusionRule();
    }
}
