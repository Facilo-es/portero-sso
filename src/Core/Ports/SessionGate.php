<?php

namespace facilo\portero\Core\Ports;

use facilo\portero\Core\Decision;
use facilo\portero\Core\Identity;

interface SessionGate
{
    /** True when someone other than this decision's user is already signed in on this browser. */
    public function signedInAsSomeoneElse(Decision $decision): bool;

    /** Writes the decision and signs the person in. Returns the user ID. */
    public function apply(Decision $decision, Identity $identity, string $connectionUid): int;
}
