<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\EndToEnd;

/**
 * [LSP] Base Protocol, ErrorCodes.
 */
enum ErrorCode: int
{
    case ParseError = -32700;
    case InvalidRequest = -32600;
    case MethodNotFound = -32601;
    case ServerNotInitialized = -32002;
}
