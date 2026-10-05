<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd\Client;

use Amp\Process\Process;

trait StartsServerTrait
{
    /**
     * @param string $projectRoot The server takes its project from its working directory.
     */
    private function startServer(string $projectRoot): ServerProcess
    {
        return new ServerProcess(Process::start(
            [PHP_BINARY, dirname(__DIR__, 3) . '/bin/php-lsp'],
            $projectRoot,
        ));
    }
}
