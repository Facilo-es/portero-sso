<?php

namespace facilo\portero\Craft\events;

use facilo\portero\Core\Connection;
use yii\base\Event;

/** Lets a module add query parameters to the request sent to the provider, such as login_hint or domain_hint. They can't replace what protects the sign-in. */
class ProviderRequestEvent extends Event
{
    public Connection $connection;
    public string $kind;

    /** @var array<string,string> */
    public array $params = [];
}
