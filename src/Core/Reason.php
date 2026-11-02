<?php

namespace facilo\portero\Core;

/** Every refusal has a code, and each code a plain explanation and a fix (spec section 5). {provider} is filled in by the caller. */
final class Reason
{
    public const TABLE = [
        'bad_signature' => ["The signature didn't match {provider}'s certificate", 'Refresh the metadata, or add the new certificate'],
        'unsigned_assertion' => ["{provider} didn't sign the assertion", 'Turn on assertion signing in {provider}'],
        'weak_algorithm' => ['{provider} signed with SHA-1, which is no longer safe', 'Switch to SHA-256 in {provider}'],
        'expired_assertion' => ['The response was out of date, or the server clocks differ', "Check this server's clock"],
        'wrong_audience' => ['The response was meant for a different app', 'Check the Entity ID in {provider}'],
        'wrong_destination' => ['The response was sent to a different address', 'Check the Sign-in URL in {provider}'],
        'replayed' => ['This response had already been used', 'Sign in again. If it repeats, check Login history'],
        'unknown_request' => ['This site has no record of starting this sign-in', 'Start again from the login page'],
        'no_browser_binding' => ['The sign-in returned to a different browser, or cookies were blocked', 'Allow cookies and start again'],
        'idp_initiated_off' => ['Sign-in started at {provider}, which is off for this connection', 'Turn it on, or start from this site'],
        'idp_error' => ['{provider} refused: {status}', "Check the person's access in {provider}"],
        'oidc_state' => ["The sign-in didn't match the one we started", 'Start again from the login page'],
        'oidc_nonce' => ["The token wasn't issued for this sign-in", 'Start again from the login page'],
        'oidc_token_invalid' => ['The token failed a check: {detail}', 'Check the issuer and client ID'],
        'oidc_exchange_failed' => ['{provider} refused the code exchange', 'Check the client secret and redirect URL'],
        'missing_email' => ["{provider} didn't send an email address", 'Add it to the attributes, or check the mapping'],
        'email_not_verified' => ["{provider} says this email isn't verified", 'Verify it in {provider}'],
        'domain_not_allowed' => ["This email's domain isn't on the allowed list", "Add the domain, or check who's assigned"],
        'no_account' => ['No account exists, and creating one is off', 'Turn on account creation, or add the person'],
        'admin_link_blocked' => ['This email belongs to an admin, and matching admins is off', 'Sign in with a password and link it, or allow it'],
        'user_suspended' => ['This account is suspended in Craft', "Unsuspend it if that's intended"],
        'user_limit' => ['This Craft edition has reached its user limit', 'Remove a user or upgrade Craft'],
        'no_group_match' => ['No group rule matched this person', 'Add a rule, or check the groups sent'],
        'groups_overage' => ['Warning: Microsoft sent too many groups to list', 'Assign only the needed groups to the app'],
        'transient_nameid' => ["{provider} sends a temporary ID, which can't identify someone twice", 'Use a persistent or email NameID'],
        'already_signed_in' => ['Someone is already signed in on this browser', 'Sign out first'],
        'connection_disabled' => ['This connection is turned off', 'Turn it on'],
        'not_fresh' => ["{provider} didn't ask this person to sign in again", 'Check that {provider} honours a forced sign-in'],
        'certs_changed' => ["{provider}'s signing certificate changed with its latest details", 'Check that this was expected at {provider}'],
        'wrong_site' => ["This connection isn't turned on for this site", 'Add this site to the connection, or sign in from one of its sites'],
        'logout_off' => ['{provider} asked to sign someone out, which is off for this connection', 'Turn on "Sign out here when they sign out at {provider}"'],
        'logout_invalid' => ["A logout request from {provider} failed a check: {detail}", "Check that {provider} signs its logout requests"],
        'password_blocked' => ['A password was used where single sign-on is required', 'Use the sign-in button'],
    ];

    public static function explain(string $code): string
    {
        return self::TABLE[$code][0] ?? $code;
    }

    public static function fix(string $code): string
    {
        return self::TABLE[$code][1] ?? '';
    }
}
