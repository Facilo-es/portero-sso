<?php

namespace facilo\portero\Craft\events;

use craft\elements\User;
use facilo\portero\Core\Connection;
use facilo\portero\Core\Decision;
use facilo\portero\Core\Identity;
use yii\base\Event;

/** Around the moment a sign-in writes to the account. Groups can be changed before, and a module can refuse the sign-in by setting isValid to false. */
class SyncUserEvent extends Event
{
    public Connection $connection;
    public Identity $identity;
    public Decision $decision;
    public User $user;

    /** @var string[] group handles */
    public array $addGroups = [];

    /** @var string[] group handles */
    public array $removeGroups = [];

    public bool $isValid = true;
}
