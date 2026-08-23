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
                ['require reads a file; filesystem access is confined (RFC 1 §8.1).', 15],
                ['require_once reads a file; filesystem access is confined (RFC 1 §8.1).', 20],
                ['include reads a file; filesystem access is confined (RFC 1 §8.1).', 25],
                ['include_once reads a file; filesystem access is confined (RFC 1 §8.1).', 30],
            ],
        );
    }

    public function testTheComposerMetadataReaderIsAllowed(): void
    {
        $this->analyse([__DIR__ . '/../../src/Index/ComposerAutoloadMap.php'], []);
    }

    protected function getRule(): Rule
    {
        return new FileInclusionRule();
    }
}
