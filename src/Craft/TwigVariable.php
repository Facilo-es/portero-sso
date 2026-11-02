<?php

namespace facilo\portero\Craft;

use craft\elements\User;
use facilo\portero\Plugin;

/** craft.portero in templates. */
class TwigVariable
{
    public function loginUrl(string $handle, ?string $return = null): string
    {
        return Plugin::getInstance()->portero->loginUrl($handle, $return);
    }

    /** @return array<int,array{handle:string, label:string, url:string}> the connections on for this site */
    public function connections(?string $return = null): array
    {
        return Plugin::getInstance()->portero->siteConnections($return);
    }

    /** @return array<int,array{id:int, connection:string, lastSignIn:?\DateTime}> how this person signs in */
    public function identities(User $user): array
    {
        return Plugin::getInstance()->portero->identities((int) $user->id);
    }
}
