<?php

namespace facilo\portero\Craft;

use Craft;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\models\UserGroup;
use facilo\portero\Core\Ports\UserDirectory;
use facilo\portero\Core\UserRef;
use facilo\portero\Craft\events\FindUserEvent;
use yii\base\Event;

final class CraftUsers implements UserDirectory
{
    public function findLinked(string $connectionUid, string $subject): ?UserRef
    {
        $id = (new Query())->select('userId')->from('{{%portero_identities}}')
            ->where(['connectionUid' => $connectionUid, 'subjectHash' => hash('sha256', $subject)])->scalar();
        return $id ? self::ref(User::find()->id($id)->status(null)->one()) : null;
    }

    public function findByEmail(string $lowercaseEmail): ?UserRef
    {
        $event = new FindUserEvent(['email' => $lowercaseEmail]);
        Event::trigger(Portero::class, Portero::EVENT_FIND_USER, $event);
        if ($event->user !== null) {
            return self::ref($event->user);
        }
        // Query params read * and commas as patterns, and the collation folds accents, so compare the result exactly.
        $users = array_filter(
            User::find()->email(Db::escapeParam($lowercaseEmail))->status(null)->limit(5)->all(),
            static fn(User $user) => mb_strtolower((string) $user->email) === $lowercaseEmail,
        );
        return count($users) === 1 ? self::ref(reset($users)) : null;
    }

    public function atUserLimit(): bool
    {
        return !Craft::$app->getUsers()->canCreateUsers();
    }

    public function groupHandles(): array
    {
        return array_map(static fn(UserGroup $group) => (string) $group->handle, Craft::$app->getUserGroups()->getAllGroups());
    }

    public static function ref(?User $user): ?UserRef
    {
        if ($user === null) {
            return null;
        }
        return new UserRef(
            (int) $user->id,
            (string) $user->email,
            $user->getName(),
            (bool) $user->admin,
            (bool) $user->suspended,
            array_map(static fn(UserGroup $group) => (string) $group->handle, $user->getGroups()),
            (string) $user->uid,
        );
    }
}
