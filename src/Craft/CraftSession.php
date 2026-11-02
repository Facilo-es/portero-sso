<?php

namespace facilo\portero\Craft;

use Craft;
use craft\elements\User;
use craft\enums\CmsEdition;
use craft\helpers\Db;
use craft\models\UserGroup;
use facilo\portero\Core\Decision;
use facilo\portero\Core\Identity;
use facilo\portero\Core\Ports\SessionGate;
use facilo\portero\Core\ProviderLogout;
use facilo\portero\Core\Refused;
use facilo\portero\Craft\events\SyncUserEvent;
use facilo\portero\Plugin;
use yii\base\Event;

final class CraftSession implements SessionGate
{
    public const SESSION_KEY = 'portero.session';
    public const PENDING_KEY = 'portero.pending';

    /** True only while Portero itself signs someone in. */
    public static bool $signingIn = false;

    public function signedInAsSomeoneElse(Decision $decision): bool
    {
        $current = Craft::$app->getUser()->getIdentity();
        return $current !== null && $current->id !== $decision->user?->id;
    }

    public function apply(Decision $decision, Identity $identity, string $connectionUid): int
    {
        $users = Craft::$app->getUsers();
        $elements = Craft::$app->getElements();
        $user = $decision->user ? User::find()->id($decision->user->id)->status(null)->one() : new User();
        if ($user === null) {
            throw new Refused('no_account', email: $identity->email);
        }
        $connection = Plugin::getInstance()->connections->get($connectionUid);
        $sync = new SyncUserEvent(['connection' => $connection, 'identity' => $identity, 'decision' => $decision, 'user' => $user, 'addGroups' => $decision->addGroups, 'removeGroups' => $decision->removeGroups]);
        Event::trigger(Portero::class, Portero::EVENT_BEFORE_SYNC_USER, $sync);
        if (!$sync->isValid) {
            throw new Refused('no_account', 'refused by a module', $identity->email);
        }
        $changed = false;
        if ($decision->action === Decision::CREATE) {
            $user->username = trim((string) $identity->email);
            $user->email = trim((string) $identity->email);
            $changed = true;
        }
        if ($decision->action === Decision::CREATE || $decision->updateDetails) {
            foreach (['firstName', 'lastName'] as $name) {
                if ($identity->$name !== null && $user->$name !== $identity->$name) {
                    $user->$name = $identity->$name;
                    $changed = true;
                }
            }
            foreach ($identity->customFields as $handle => $value) {
                $user->setFieldValue($handle, $value);
                $changed = true;
            }
        }
        if ($decision->newEmail !== null) {
            $user->email = $decision->newEmail;
            $changed = true;
        }
        if ($decision->makeAdmin !== null && (bool) $user->admin !== $decision->makeAdmin) {
            $user->admin = $decision->makeAdmin;
            $changed = true;
        }
        if ($changed && !$elements->saveElement($user, false)) {
            throw new Refused('no_account', 'could not save the account', $identity->email);
        }
        if ($decision->action === Decision::CREATE || $user->pending) {
            $users->activateUser($user);
        }
        if ($user->locked) {
            $users->unlockUser($user);
        }
        $this->applyGroups($user, $sync->addGroups, $sync->removeGroups);
        $this->link($connectionUid, $identity->subject, (int) $user->id);

        $general = Craft::$app->getConfig()->getGeneral();
        $auth = Craft::$app->getAuth();
        Event::trigger(Portero::class, Portero::EVENT_AFTER_SYNC_USER, $sync);

        $note = ['connection' => $connectionUid, 'userId' => (int) $user->id, 'since' => time(), 'provider' => $identity->providerSession];
        if ($connection?->acceptProviderLogout && $identity->logoutName !== null) {
            $note['track'] = [ProviderLogout::key($connectionUid, $identity->logoutName), $identity->sessionRef !== null ? ProviderLogout::key($connectionUid, $identity->sessionRef) : null];
        }
        $trusted = $connection?->trustProvider2fa;
        if (!$general->disable2fa && !$trusted && $auth->hasActiveMethod($user)) {
            // Same hand-off as Craft's password login: nobody is signed in until the second step passes.
            $auth->setUser($user, $general->userSessionDuration);
            Craft::$app->getSession()->set(self::PENDING_KEY, $note);
            return (int) $user->id;
        }
        self::$signingIn = true;
        try {
            if (!Craft::$app->getUser()->login($user, $general->userSessionDuration ?? 0)) {
                throw new Refused('no_account', 'Craft refused the login', $identity->email);
            }
        } finally {
            self::$signingIn = false;
        }
        Plugin::getInstance()->portero->sessionOpened($note);
        return (int) $user->id;
    }

    /**
     * @param string[] $add
     * @param string[] $remove
     */
    private function applyGroups(User $user, array $add, array $remove): void
    {
        if (Craft::$app->edition->value < CmsEdition::Pro->value || ($add === [] && $remove === [])) {
            return;
        }
        $service = Craft::$app->getUserGroups();
        $ids = array_map(static fn(UserGroup $group) => (int) $group->id, $service->getGroupsByUserId($user->id));
        foreach ($add as $handle) {
            $group = $service->getGroupByHandle($handle);
            if ($group) {
                $ids[] = (int) $group->id;
            }
        }
        foreach ($remove as $handle) {
            $group = $service->getGroupByHandle($handle);
            if ($group) {
                $ids = array_diff($ids, [(int) $group->id]);
            }
        }
        Craft::$app->getUsers()->assignUserToGroups($user->id, array_values(array_unique($ids)));
    }

    private function link(string $connectionUid, string $subject, int $userId): void
    {
        Craft::$app->getDb()->createCommand()->upsert('{{%portero_identities}}', [
            'connectionUid' => $connectionUid,
            'subjectHash' => hash('sha256', $subject),
            'subject' => mb_substr($subject, 0, 512),
            'userId' => $userId,
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
            'lastSignIn' => Db::prepareDateForDb(new \DateTime()),
        ], ['lastSignIn' => Db::prepareDateForDb(new \DateTime()), 'userId' => $userId], [], false)->execute();
    }
}
