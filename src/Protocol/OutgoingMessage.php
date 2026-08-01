<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Protocol;

use JsonSerializable;

/**
 * A message the server writes to the client — a {@see ResponseMessage} or a
 * server-initiated {@see OutgoingRequest}. The transport's write side accepts any of
 * these, so responses and requests frame through one channel.
 */
interface OutgoingMessage extends JsonSerializable
{
}
