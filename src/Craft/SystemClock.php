<?php

namespace facilo\portero\Craft;

use facilo\portero\Core\Ports\Clock;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
