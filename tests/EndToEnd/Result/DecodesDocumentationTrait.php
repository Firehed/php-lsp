<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Result;

use PHPUnit\Framework\Assert;
use stdClass;

trait DecodesDocumentationTrait
{
    /**
     * Documentation is a string or MarkupContent.
     */
    private static function documentationOf(mixed $documentation): ?string
    {
        if ($documentation === null || is_string($documentation)) {
            return $documentation;
        }
        Assert::assertInstanceOf(stdClass::class, $documentation, 'documentation is a string or MarkupContent');
        $value = $documentation->value ?? null;
        Assert::assertIsString($value, 'MarkupContent has a value');

        return $value;
    }
}
