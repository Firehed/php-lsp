<?php

declare(strict_types=1);

namespace Fixtures\Completion;

use Trap\RealType;

/**
 * A parameter-type completion where the imported class shares a leading
 * segment with a bogus PSR-4 candidate the walker mints under the same
 * `Trap\` prefix. The prefix is narrow enough that the completion source's
 * search response is bounded to a couple of matches, but wide enough that the
 * bogus candidate is in it — so a probe that autoloads user code shows up in
 * the tracker.
 */
final class AutoloadTrapEditor
{
    public function typePrefix(Real/*|type_prefix*/
}
