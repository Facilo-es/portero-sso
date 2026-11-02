<?php

namespace facilo\portero\Core;

use facilo\portero\Core\Ports\UserDirectory;

/** Finds the account and works out what a sign-in would change (spec F5, F6 and section 6). Writes nothing. */
final class AccessRules
{
    /** @param string[] $breakGlassUids */
    public function __construct(private readonly UserDirectory $users, private readonly array $breakGlassUids = [])
    {
    }

    /** @throws Refused */
    public function decide(Connection $connection, Identity $identity): Decision
    {
        if (Email::foldsToAscii($identity->email)) {
            throw new Refused('missing_email', 'not an email address');
        }
        $email = Email::normalize($identity->email);
        $hasDomains = $connection->allowedDomains !== [];

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE)) {
            throw new Refused('missing_email', 'not an email address');
        }

        if ($identity->emailVerified === false) {
            throw new Refused('email_not_verified', email: $email);
        }
        if ($hasDomains) {
            if ($email === '') {
                throw new Refused('missing_email');
            }
            if (!Email::domainAllowed($email, $connection->allowedDomains)) {
                throw new Refused('domain_not_allowed', email: $email);
            }
            if ($identity->hostedDomain !== null && !in_array(Email::normalize($identity->hostedDomain), array_map(Email::normalize(...), $connection->allowedDomains), true)) {
                throw new Refused('domain_not_allowed', email: $email);
            }
        }

        // OIDC with no email_verified claim: the address counts only behind an allowed-domains list.
        $emailUsable = $email !== '' && ($identity->emailVerified === true || $connection->protocol === Connection::SAML || $hasDomains);

        $user = $this->users->findLinked($connection->uid, $identity->subject);
        $action = Decision::SIGN_IN;

        if ($user === null && $connection->matchByEmail && $hasDomains && $emailUsable) {
            $user = $this->users->findByEmail($email);
            if ($user !== null) {
                if ($user->admin && !$connection->matchAdmins) {
                    throw new Refused('admin_link_blocked', email: $email);
                }
                $action = Decision::LINK;
            }
        }
        if ($user === null) {
            if (!$connection->createAccounts) {
                throw new Refused($emailUsable ? 'no_account' : 'missing_email', email: $email ?: null);
            }
            if (!$emailUsable) {
                throw new Refused('missing_email');
            }
            if ($this->users->findByEmail($email) !== null) {
                // Creating would collide with an account nobody chose to link. Never merge.
                throw new Refused('no_account', email: $email);
            }
            if ($this->users->atUserLimit()) {
                throw new Refused('user_limit', email: $email);
            }
            $action = Decision::CREATE;
        }
        if ($user?->suspended) {
            throw new Refused('user_suspended', email: $email);
        }

        $warnings = [];
        [$add, $remove] = $this->groupChanges($connection, $identity, $user, $warnings);

        $newEmail = null;
        if ($user !== null && $connection->updateOnSignIn && $emailUsable && $email !== Email::normalize($user->email)) {
            $owner = $this->users->findByEmail($email);
            if ($owner !== null && $owner->id !== $user->id) {
                $warnings[] = 'email_taken';
            } else {
                $newEmail = trim((string) $identity->email);
            }
        }

        return new Decision(
            $action,
            $user,
            $add,
            $remove,
            $this->adminChange($connection, $identity, $user),
            $newEmail,
            $connection->updateOnSignIn,
            $warnings,
        );
    }

    /**
     * @param string[] $warnings
     * @return array{0:string[],1:string[]}
     * @throws Refused
     */
    private function groupChanges(Connection $connection, Identity $identity, ?UserRef $user, array &$warnings): array
    {
        if ($identity->groupsOverage) {
            $warnings[] = 'groups_overage';
            if ($connection->requireGroupMatch) {
                throw new Refused('no_group_match', 'groups_overage', $identity->email);
            }
            return [array_values(array_diff($connection->defaultGroups, $user->groups ?? [])), []];
        }

        $named = $matched = [];
        foreach ($connection->groupRules as $rule) {
            $named[] = $rule['group'];
            if (in_array($rule['value'], $identity->groups, true)) {
                $matched[] = $rule['group'];
            }
        }
        if ($connection->groupsByHandle) {
            array_push($matched, ...array_intersect($identity->groups, $this->users->groupHandles()));
        }
        if ($connection->requireGroupMatch && $matched === []) {
            throw new Refused('no_group_match', email: $identity->email);
        }
        $wanted = array_unique([...$connection->defaultGroups, ...$matched]);
        $current = $user->groups ?? [];
        // Fully managed groups never take a break-glass account out of what keeps it useful in an outage.
        $managed = $connection->manageAllGroups && !in_array($user?->uid, $this->breakGlassUids, true);
        return [
            array_values(array_diff($wanted, $current)),
            array_values(array_diff($managed ? $current : array_intersect($named, $current), $wanted)),
        ];
    }

    private function adminChange(Connection $connection, Identity $identity, ?UserRef $user): ?bool
    {
        if ($connection->adminRule === null || $identity->groupsOverage) {
            return null;
        }
        $wanted = in_array($connection->adminRule, $identity->groups, true);
        if (!$wanted && $user !== null && in_array($user->uid, $this->breakGlassUids, true)) {
            return null;
        }
        return $user !== null && $user->admin === $wanted ? null : ($wanted ?: ($user === null ? null : false));
    }
}
