<?php

namespace Fixtures\Recovery {
    $dropped = ;

    interface DroppedInInterface extends \Countable
    {
        public function dropped
    }

    trait DroppedInTrait
    {
        public function dropped
    }

    enum DroppedInEnum: string implements \JsonSerializable
    {
        case A = 'a';

        public function dropped
    }

    class DroppedInClass extends \ArrayObject implements \Countable
    {
        public function dropped

        public function method(int $count): int
        {
            $dropped = ;
            $closure = function (int $value) use ($count): int {
                $dropped = ;
            };
            if ($count) {
                $dropped = ;
            } elseif ($count > 1) {
                $dropped = ;
            } else {
                $dropped = ;
            }
            while ($count) {
                $dropped = ;
            }
            do {
                $dropped = ;
            } while ($count);
            for ($i = 0; $i < $count; $i++) {
                $dropped = ;
            }
            foreach ([$count] as $key => $value) {
                $dropped = ;
            }
            try {
                $dropped = ;
            } catch (\Exception $e) {
                $dropped = ;
            } finally {
                $dropped = ;
            }
            declare(ticks=1) {
                $dropped = ;
            }
            {
                $dropped = ;
            }
            return $count;
        }
    }

    function droppedInFunction(): void
    {
        $dropped = ;
    }
}
