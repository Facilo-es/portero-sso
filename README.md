# Portero SSO

Work logins for Craft CMS. Portero SSO lets staff sign in to the Craft control panel, and to members' areas on your site, with their work account through SAML 2.0 or OpenID Connect.

## What it does

- Sign in with Okta, Microsoft Entra ID, Google Workspace, Keycloak, or any other SAML 2.0 or OpenID Connect provider.
- More than one provider on the same site, and different providers on different sites.
- Sign out at the provider and the Craft session ends too, through signed SAML requests or OpenID Connect back-channel logout.
- A guided setup with a **Test sign-in** step that shows what the provider sent and changes nothing.
- Accounts created on first sign-in, with Craft groups set from the provider's groups (Craft Pro).
- Sign-in rules, break-glass accounts and a recovery command, so a provider outage can't lock you out.
- Login history with a plain reason for every refusal.
- One command to move from the plugin SAML SSO Service Provider.
- Control panel screens in English and Spanish.

## Requirements

- Craft CMS 6, Team edition or higher. For Craft 5, use Portero SSO 1.x.
- PHP 8.5 or later
- Group rules need Craft Pro

Portero 2.x is a beta while Craft 6 is in alpha. It was tested on Craft 6.0.0-alpha.20 with PHP 8.5, MySQL 8.4 and real SAML and OpenID Connect sign-ins through Keycloak. The same licence covers 1.x and 2.x.

## Install

```
composer require facilo/portero-sso
php craft plugin/install portero-sso
```

Then open **Portero SSO** in the control panel menu.

## Documentation

Start at [docs/README.md](docs/README.md). There are guides for Okta, Microsoft Entra ID, Google Workspace, Keycloak, other SAML providers and other OpenID Connect providers. Each says what was tested live and what wasn't.

## Support and security

Email support@facilo.es or open an issue on GitHub. We don't guarantee response times. To report a security problem, see [SECURITY.md](SECURITY.md).

## License

Craft License. See [LICENSE.md](LICENSE.md).
