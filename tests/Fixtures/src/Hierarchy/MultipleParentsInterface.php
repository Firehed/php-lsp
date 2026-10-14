<?php

declare(strict_types=1);

namespace Fixtures\Hierarchy;

use Fixtures\Domain\Entity;

interface MultipleParentsInterface extends MiddleInterface, Entity
{
}
