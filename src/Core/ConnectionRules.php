<?php

namespace facilo\portero\Core;

/** What can't be saved, whichever screen or file it comes from. */
final class ConnectionRules
{
    private const MULTI_TENANT = '#login\.microsoftonline\.com/(common|organizations|consumers)(/|$)#i';

    /** @return array<string,string> field => message */
    public static function check(array $c): array
    {
        $errors = [];
        if (trim((string) ($c['name'] ?? '')) === '') {
            $errors['name'] = 'Name cannot be blank.';
        }
        if (!preg_match('/^[a-z][a-z0-9-]{0,62}$/', (string) ($c['handle'] ?? ''))) {
            $errors['handle'] = 'Use lowercase letters, numbers and hyphens.';
        }
        if (($c['protocol'] ?? '') === Connection::OIDC && ($c['preset'] ?? '') === 'google' && !empty($c['createAccounts']) && empty($c['allowedDomains'])) {
            $errors['allowedDomains'] = 'Add your domain. Without it, anyone with a Google account could get an account here.';
        }
        if (($c['protocol'] ?? '') === Connection::OIDC) {
            $secret = (string) ($c['oidc']['clientSecret'] ?? '');
            if ($secret !== '' && !self::isEnvReference($secret)) {
                $errors['clientSecret'] = 'Secrets must be environment variables, so they never reach your repository.';
            }
            if (preg_match(self::MULTI_TENANT, (string) ($c['oidc']['issuer'] ?? ''))) {
                $errors['issuer'] = "This address lets anyone with a Microsoft account reach your sign-in. Use your own tenant's address.";
            }
        }
        if (($c['protocol'] ?? '') === Connection::SAML) {
            $saml = $c['saml'] ?? [];
            if (!empty($saml['signRequests']) && ($saml['nameIdFormat'] ?? '') === '') {
                $errors['nameIdFormat'] = 'Signed requests need a NameID format.';
            }
            if (!empty($c['acceptProviderLogout']) && ($saml['sloUrl'] ?? '') === '') {
                $errors['acceptProviderLogout'] = "Add your provider's logout address on step 3 first, so Portero can answer it.";
            }
            foreach (['spPrivateKey', 'spCertificate'] as $key) {
                if (($saml[$key] ?? '') !== '' && !self::isEnvReference((string) $saml[$key])) {
                    $errors[$key] = 'Secrets must be environment variables, so they never reach your repository.';
                }
            }
            if ((!empty($saml['signRequests']) || !empty($saml['encryptAssertions'])) && (($saml['spPrivateKey'] ?? '') === '' || ($saml['spCertificate'] ?? '') === '')) {
                $errors['spPrivateKey'] = 'Signed requests and encrypted assertions need a key pair. Run php craft portero/keys/generate.';
            }
        }
        if (!empty($c['matchByEmail']) && empty($c['allowedDomains'])) {
            $errors['matchByEmail'] = 'Needs allowed domains.';
        }
        return $errors;
    }

    public static function isEnvReference(string $value): bool
    {
        return (bool) preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/', $value);
    }
}
