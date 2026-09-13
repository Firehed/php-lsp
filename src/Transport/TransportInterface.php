<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Transport;

use Firehed\PhpLsp\Protocol\Message;
use Firehed\PhpLsp\Protocol\OutgoingMessageInterface;

interface TransportInterface
{
    public function read(): Message|MalformedFrame|EndOfStream;

    public function write(OutgoingMessageInterface $message): void;

    public function close(): void;
}
