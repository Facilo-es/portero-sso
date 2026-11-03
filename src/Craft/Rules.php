<?php

namespace facilo\portero\Craft;

use Craft;
use craft\elements\User;
use facilo\portero\Plugin;
use yii\base\Component;

/** Who must use single sign-on, and the guard that stops a site locking itself out. */
class Rules extends Component
{
    public const CONFIG_PATH = 'portero.rules';
    public const NOBODY = 'nobody';
    public const EVERYONE = 'everyone';
    public const DEFAULT_DAYS = 30;

    public function all(): array
    {
        return (Craft::$app->getProjectConfig()->get(self::CONFIG_PATH) ?? []) + [
            'mode' => self::NOBODY,
            'breakGlassUserUids' => [],
            'autoRedirect' => false,
            'historyDays' => self::DEFAULT_DAYS,
        ];
    }

    public function save(array $rules): void
    {
        Craft::$app->getProjectConfig()->set(self::CONFIG_PATH, $rules, 'Portero sign-in rules');
    }

    public function isExempt(User $user): bool
    {
        $rules = $this->all();
        return $rules['mode'] !== self::EVERYONE || in_array($user->uid, $rules['breakGlassUserUids'] ?? [], true);
    }

    /** True when this connection is the last tested way in while passwords are off for most people. */
    public function wouldStrand(string $uid): bool
    {
        return $this->all()['mode'] === self::EVERYONE && !Plugin::getInstance()->connections->anyTestPassed($uid);
    }

    /** @return string[] what's missing before the rules can be turned on */
    public function missingForLockout(array $breakGlassUids): array
    {
        $missing = [];
        if (!Plugin::getInstance()->connections->anyTestPassed()) {
            $missing[] = Craft::t('portero-sso', 'pass a test sign-in');
        }
        $ready = false;
        foreach ($breakGlassUids as $uid) {
            $user = User::find()->uid($uid)->status(null)->addSelect(['users.password'])->one();
            $ready = $ready || ($user && $user->admin && !$user->suspended && $user->password);
        }
        if (!$ready) {
            $missing[] = Craft::t('portero-sso', 'add a break-glass admin with a password');
        }
        return $missing;
    }

    /**
     * The message shows only after the right password, so it can't tell a stranger which accounts exist.
     * Craft 6 fires its password event without the user, so the plugin passes the one Craft just looked up.
     */
    public function refusesPassword(User $user, ?string $password, ?string $hash): bool
    {
        if ($this->isExempt($user)) {
            return false;
        }
        if ($password !== null) {
            try {
                if (!Craft::$app->getSecurity()->validatePassword($password, (string) $hash)) {
                    return false;
                }
            } catch (\yii\base\InvalidArgumentException) {
                return false;
            }
        }
        Plugin::getInstance()->portero->recordPasswordRefusal($user);
        return true;
    }

    /** Catches what never reaches the password check, such as signing in straight after activating an account. */
    public function allowsLogin(User $user, bool $cookieBased): bool
    {
        return $cookieBased
            || $this->isExempt($user)
            || CraftSession::$signingIn
            || (Craft::$app->getSession()->get(CraftSession::PENDING_KEY)['userId'] ?? null) === (int) $user->id
            || Craft::$app->getUser()->getImpersonator() !== null;
    }

    public function passwordRefusedMessage(): string
    {
        $enabled = Plugin::getInstance()->connections->enabled();
        return Craft::t('portero-sso', 'Your organization signs in with {provider}. Use the button above.', [
            'provider' => count($enabled) === 1 ? $enabled[0]->providerLabel() : Craft::t('portero-sso', 'your work account'),
        ]);
    }

    public function turnOff(): void
    {
        // The recovery route has to work on production, where project config is normally read-only.
        $config = Craft::$app->getProjectConfig();
        $readOnly = $config->readOnly;
        $config->readOnly = false;
        $this->save(['mode' => self::NOBODY, 'autoRedirect' => false] + $this->all());
        $config->flush();
        $config->readOnly = $readOnly;
    }
}
