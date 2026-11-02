<?php

namespace facilo\portero\Core\Ports;

interface Clock
{
    public function now(): int;
}
