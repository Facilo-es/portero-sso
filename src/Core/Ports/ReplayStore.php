<?php

namespace facilo\portero\Core\Ports;

interface ReplayStore
{
    /** False if this hash was already stored. Must be atomic. */
    public function remember(string $assertionHash, int $expiresAt): bool;
}
