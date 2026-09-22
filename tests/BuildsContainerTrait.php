<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests;

use Firehed\Container\AutoDetect;
use Firehed\Container\TypedContainerInterface;

/**
 * Loads the project container for tests that stand up a real Server via
 * {@see \Firehed\PhpLsp\Server::forProject}. Shares one wiring place with
 * production: tests exercise the same definitions in `config/`.
 */
trait BuildsContainerTrait
{
    private function buildContainer(): TypedContainerInterface
    {
        $_ENV['ENVIRONMENT'] ??= 'dev';
        return AutoDetect::from(__DIR__ . '/../config');
    }
}
