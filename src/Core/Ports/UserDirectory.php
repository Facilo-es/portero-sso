<?php

namespace facilo\portero\Core\Ports;

use facilo\portero\Core\UserRef;

interface UserDirectory
{
    public function findLinked(string $connectionUid, string $subject): ?UserRef;

    public function findByEmail(string $lowercaseEmail): ?UserRef;

    public function atUserLimit(): bool;

    /** @return string[] the handle of every group on this site */
    public function groupHandles(): array;
}
