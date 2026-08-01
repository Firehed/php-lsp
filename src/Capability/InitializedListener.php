<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Capability;

/**
 * Notified once the client sends `initialized`, when the negotiated
 * {@see SessionCapabilities} are settled and dynamic registration may proceed.
 * Separate from {@see \Firehed\PhpLsp\Handler\LifecycleHandler} so post-initialize
 * actions are added without that handler depending on each feature.
 */
interface InitializedListener
{
    public function onInitialized(SessionCapabilities $capabilities): void;
}
