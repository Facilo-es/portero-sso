<?php

namespace facilo\portero\Core;

/** One provider connection, as stored in project config. */
final class Connection
{
    public const SAML = 'saml';
    public const OIDC = 'oidc';

    public const PRESETS = [
        'okta' => ['label' => 'Okta', 'protocols' => [self::SAML, self::OIDC]],
        'entra' => ['label' => 'Microsoft Entra ID', 'protocols' => [self::SAML, self::OIDC]],
        'google' => ['label' => 'Google Workspace', 'protocols' => [self::SAML, self::OIDC]],
        'keycloak' => ['label' => 'Keycloak', 'protocols' => [self::SAML, self::OIDC]],
        'saml' => ['label' => 'Other SAML provider', 'protocols' => [self::SAML]],
        'oidc' => ['label' => 'Other OpenID Connect provider', 'protocols' => [self::OIDC]],
    ];

    private const ENTRA_CLAIMS = 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/';

    public function __construct(
        public readonly string $uid,
        public readonly string $name,
        public readonly string $handle,
        public readonly bool $enabled,
        public readonly string $preset,
        public readonly string $protocol,
        public readonly string $buttonLabel,
        public readonly array $allowedDomains = [],
        public readonly bool $createAccounts = false,
        public readonly bool $matchByEmail = false,
        public readonly bool $matchAdmins = false,
        public readonly bool $updateOnSignIn = true,
        public readonly array $defaultGroups = [],
        public readonly array $groupRules = [],
        public readonly bool $requireGroupMatch = false,
        public readonly ?string $adminRule = null,
        public readonly bool $trustProvider2fa = false,
        public readonly ?int $maxSessionHours = null,
        public readonly bool $signOutOfProvider = false,
        public readonly array $attributeMap = [],
        public readonly array $saml = [],
        public readonly array $oidc = [],
        public readonly ?int $lastTestPassed = null,
        public readonly array $sites = [],
        public readonly string $addressSite = '',
        public readonly bool $showOnCpLogin = true,
        public readonly bool $acceptProviderLogout = false,
        public readonly bool $groupsByHandle = false,
        public readonly bool $manageAllGroups = false,
    ) {
    }

    public static function fromArray(string $uid, array $c): self
    {
        return new self(
            $uid,
            (string) ($c['name'] ?? ''),
            (string) ($c['handle'] ?? ''),
            (bool) ($c['enabled'] ?? true),
            (string) ($c['preset'] ?? 'saml'),
            (string) ($c['protocol'] ?? self::SAML),
            (string) ($c['buttonLabel'] ?? ''),
            array_values((array) ($c['allowedDomains'] ?? [])),
            (bool) ($c['createAccounts'] ?? false),
            (bool) ($c['matchByEmail'] ?? false),
            (bool) ($c['matchAdmins'] ?? false),
            (bool) ($c['updateOnSignIn'] ?? true),
            array_values((array) ($c['defaultGroups'] ?? [])),
            array_values((array) ($c['groupRules'] ?? [])),
            (bool) ($c['requireGroupMatch'] ?? false),
            ($c['adminRule'] ?? '') !== '' ? (string) $c['adminRule'] : null,
            (bool) ($c['trustProvider2fa'] ?? false),
            !empty($c['maxSessionHours']) ? (int) $c['maxSessionHours'] : null,
            (bool) ($c['signOutOfProvider'] ?? false),
            (array) ($c['attributeMap'] ?? self::defaultAttributes((string) ($c['preset'] ?? ''), (string) ($c['protocol'] ?? self::SAML))),
            (array) ($c['saml'] ?? []),
            (array) ($c['oidc'] ?? []),
            !empty($c['lastTestPassed']) ? (int) $c['lastTestPassed'] : null,
            array_values((array) ($c['sites'] ?? [])),
            (string) ($c['addressSite'] ?? ''),
            (bool) ($c['showOnCpLogin'] ?? true),
            (bool) ($c['acceptProviderLogout'] ?? false),
            (bool) ($c['groupsByHandle'] ?? false),
            (bool) ($c['manageAllGroups'] ?? false),
        );
    }

    public static function defaultAttributes(string $preset, string $protocol): array
    {
        if ($protocol === self::OIDC) {
            return ['email' => 'email', 'firstName' => 'given_name', 'lastName' => 'family_name', 'groups' => 'groups'];
        }
        if ($preset === 'entra') {
            return [
                'email' => self::ENTRA_CLAIMS . 'emailaddress',
                'firstName' => self::ENTRA_CLAIMS . 'givenname',
                'lastName' => self::ENTRA_CLAIMS . 'surname',
                'groups' => 'http://schemas.microsoft.com/ws/2008/06/identity/claims/groups',
            ];
        }
        return ['email' => 'email', 'firstName' => 'firstName', 'lastName' => 'lastName', 'groups' => 'groups'];
    }

    public function providerLabel(): string
    {
        return in_array($this->preset, ['saml', 'oidc'], true) ? $this->name : self::PRESETS[$this->preset]['label'];
    }
}
