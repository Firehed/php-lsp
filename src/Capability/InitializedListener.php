<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Capability;

/**
 * Notified once the client sends `initialized` ([LSP] "Server lifecycle"), the
 * point at which the negotiated {@see SessionCapabilities} are settled and the
 * server may act on them — in particular, register dynamic capabilities the client
 * declared support for ([LSP] Register Capability).
 *
 * The hook is separate from the {@see \Firehed\PhpLsp\Handler\LifecycleHandler}
 * that owns lifecycle state so that post-initialize actions are added without that
 * handler growing a dependency on each feature.
 */
interface InitializedListener
{
    public function onInitialized(SessionCapabilities $capabilities): void;
}
