<?php

declare(strict_types=1);

namespace Firehed\PhpLsp;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableResourceStream;
use Amp\ByteStream\WritableStream;

return [
    ReadableResourceStream::class => fn () => new ReadableResourceStream(STDIN),
    ReadableStream::class => ReadableResourceStream::class,
    WritableResourceStream::class => fn () => new WritableResourceStream(STDOUT),
    WritableStream::class => WritableResourceStream::class,

    Transport\StreamTransport::class,
    Transport\TransportInterface::class => Transport\StreamTransport::class,
];
