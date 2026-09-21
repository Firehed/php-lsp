<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Protocol;

/**
 * JSON-RPC 2.0 and LSP 3.17 error codes.
 *
 * The JSON-RPC 2.0 codes are defined in section 5.1 of the JSON-RPC
 * specification. `ServerNotInitialized` sits in the JSON-RPC server-reserved
 * range and is named by the LSP "Server lifecycle" section.
 */
enum ErrorCode: int
{
    case ParseError = -32700;
    case InvalidRequest = -32600;
    case MethodNotFound = -32601;
    case InvalidParams = -32602;
    case InternalError = -32603;
    case ServerNotInitialized = -32002;

    public function defaultMessage(): string
    {
        return match ($this) {
            self::ParseError => 'Parse error',
            self::InvalidRequest => 'Invalid Request',
            self::MethodNotFound => 'Method not found',
            self::InvalidParams => 'Invalid params',
            self::InternalError => 'Internal error',
            self::ServerNotInitialized => 'Server not initialized',
        };
    }
}
