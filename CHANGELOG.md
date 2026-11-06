# Release Notes for Portero SSO

## 1.0.2 - 2026-11-06

### Fixed
- SAML sign-in works with php-saml 4.4.1. Portero now passes the port as text, so the address checked against the provider's reply no longer gains a stray `:443` and valid sign-ins are no longer refused as sent to the wrong address.

### Security
- Requires php-saml 4.4.1 or later, which moves to xmlseclibs 4 and closes the xmlseclibs advisories published in August 2026.

## 1.0.1 - 2026-11-02

### Fixed
- Composer now allows the Craft and Yii installer plugins, so a fresh `composer install` of the plugin works.

## 1.0.0 - 2026-11-02

### Added
- Initial release.
- Unlimited connections, stored in project config, with presets for Okta, Microsoft Entra ID, Google Workspace and Keycloak, and for any other SAML 2.0 or OpenID Connect provider.
- SAML 2.0 sign-in with required signed assertions, optional signed requests and encrypted assertions, metadata by URL, pasted XML or typed fields, and single logout started from Craft or by the provider.
- OpenID Connect sign-in with the Authorization Code flow and PKCE, discovery, RS256 and ES256 tokens, and key caching.
- Sign-in buttons on the control panel login page, and `craft.portero.loginUrl()` for front-end templates.
- Account matching by provider ID, optional matching by email with allowed domains, and optional account creation.
- Attribute mapping, custom user field mapping, group rules, default groups and an optional admin rule.
- Sign-in rules, break-glass accounts, an option to send everyone straight to the provider, and the `portero/rules/off` recovery command.
- Test sign-in, which shows what the provider sent and changes nothing.
- Login history with plain explanations, masked emails and no IP addresses.
- Option to trust the provider's two-step sign-in, a longest-session limit and sign-out of the provider.
- A "Confirm with {provider}" button on Craft's confirm-your-identity box.
- `portero/import/saml-sp`, with `--dry-run`, to move from SAML SSO Service Provider.
- Daily metadata refresh, certificate expiry alerts, `portero/metadata/refresh`, `portero/doctor` and `portero/keys/generate`.
- A one-page security summary for each connection.
- Multi-site: choose which sites a connection works on, and which site's address your provider gets.
- Logout started by the provider: signed SAML requests and OpenID Connect back-channel logout, off by default.
- Linked sign-ins on each person's page in the control panel, with a way to remove them.
- Option to match provider groups to Craft group handles, and to let the provider manage all of a person's groups.
- Events for developers, and `craft.portero.connections()` and `craft.portero.identities()`.
- A setting to leave a connection off the control panel login page.
- Complete Spanish translation of the control panel screens.
- Documentation for each provider, switching, troubleshooting, security and a written risk assessment.
