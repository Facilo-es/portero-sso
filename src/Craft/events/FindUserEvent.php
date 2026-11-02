<?php

namespace facilo\portero\Craft\events;

use craft\elements\User;
use yii\base\Event;

/** Lets a module match an account some other way than by email. Portero's admin and suspended checks still apply to whoever it picks. */
class FindUserEvent extends Event
{
    public string $email;
    public ?User $user = null;
}
