<?php

namespace Fixtures\EdgeCases;

// Edge case: a backing type part-way through being typed. PHP rejects it, but
// it parses.
enum EnumBackedByAClassName: Str
{
}
