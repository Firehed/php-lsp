<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Protocol;

use JsonSerializable;

/**
 * A message the server writes to the client: a {@see ResponseMessage} answering a
 * client request, or a server-initiated {@see OutgoingRequest} (e.g.
 * `client/registerCapability`). It is the counterpart to the inbound {@see Message}
 * hierarchy — outbound messages serialize rather than parse — and is what the
 * transport's write side accepts, so both paths frame through one channel.
 */
interface OutgoingMessage extends JsonSerializable
{
}
