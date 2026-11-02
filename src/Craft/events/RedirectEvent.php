<?php

namespace facilo\portero\Craft\events;

use facilo\portero\Core\Connection;
use yii\base\Event;

/** The address someone goes to after signing in. */
class RedirectEvent extends Event
{
    public Connection $connection;
    public string $url;
}
