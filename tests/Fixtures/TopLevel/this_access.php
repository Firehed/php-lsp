<?php

// $this outside any class - should return null for completion
$this->/*|this_toplevel*/

// Chained $this outside any class - should also return null
$this->foo->/*|this_chained_toplevel*/
