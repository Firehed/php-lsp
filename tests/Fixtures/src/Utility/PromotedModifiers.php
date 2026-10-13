<?php

declare(strict_types=1);

namespace Fixtures\Utility;

final class PromotedModifiers
{
    public function __construct(
        public int $publicId,
        protected int $protectedId,
        private int $privateId,
        readonly int $readonlyId,
        private(set) int $privateSetCount,
        protected(set) int $protectedSetCount,
        int $hookedId { get => $this->hookedId; },
        int $notPromoted,
    ) {
    }
}
