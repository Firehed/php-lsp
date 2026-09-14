<?php

declare(strict_types=1);

namespace Firehed\PhpLsp;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;
use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\WritableResourceStream;

return [
    ReadableStream::class => ReadableResourceStream::class,
    ReadableResourceStream::class => fn () =>  new ReadableResourceStream(STDIN),
    WritableStream::class => WritableResourceStream::class,
    WritableResourceStream::class => fn () => new WritableResourceStream(STDOUT),

    Transport\StreamTransport::class,
    Transport\TransportInterface::class => Transport\StreamTransport::class,
];
