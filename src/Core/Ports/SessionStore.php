<?php

namespace facilo\portero\Core\Ports;

interface SessionStore
{
    /**
     * Ends every session of this connection that matches. A null name or empty list doesn't narrow the match.
     *
     * @param string[] $refHashes
     * @return int[] the users signed out
     */
    public function end(string $connectionUid, ?string $nameHash, array $refHashes): array;
}
