<?php

namespace facilo\portero\Core\Ports;

interface KeyCache
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value, int $seconds): void;
}
