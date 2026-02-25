<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Transport;

use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Protocol\ResponseMessage;

interface TransportInterface
{
    public function read(): ?Message;

    public function write(ResponseMessage $response): void;

    public function close(): void;
}
