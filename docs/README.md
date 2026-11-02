# Portero SSO documentation

Portero SSO lets your team sign in to the Craft control panel, and to members' areas on your site, with their work account. It speaks SAML 2.0 and OpenID Connect.

## Guides

- [Okta](okta.md)
- [Microsoft Entra ID](entra.md)
- [Google Workspace](google.md)
- [Keycloak](keycloak.md)
- [Any other SAML provider](saml.md)
- [Any other OpenID Connect provider](oidc.md)
- [Switching from SAML SSO Service Provider](switching.md)
- [Multi-site](multi-site.md)
- [For developers](developers.md): events and template helpers
- [Troubleshooting](troubleshooting.md)
- [Security](security.md) and the [risk assessment](security/risk-assessment.md)

## Requirements

- Craft CMS 5.6 or later, Team edition or higher. Craft Solo holds one user, so Portero can't add accounts there.
- PHP 8.2 or later.
- Group rules need Craft Pro, because Team allows only one user group.
- A provider that supports SAML 2.0 or OpenID Connect.

## Install

1. Install the plugin from the Craft Plugin Store, or run `composer require facilo/portero-sso`.
2. Run `php craft plugin/install portero-sso`.
3. Open **Portero SSO** in the control panel menu. It has three pages: **Connections**, **Sign-in rules** and **Login history**.

Plugins are free to try on development and staging sites. Buy the licence when you go live.

## Your first connection

Press **Add a connection**. Setup has five steps. You can leave and come back at any time.

1. **Choose your provider.** Pick Okta, Microsoft Entra ID, Google Workspace, Keycloak, Other SAML provider or Other OpenID Connect provider. Give it a **Name**. Pick the **Sign-in method** (SAML is pre-selected for the four named providers). Edit the **Button label** if you like. It defaults to `Sign in with {provider}`.
2. **Tell your provider about this site.** Copy the addresses shown into your provider. For SAML you get **Entity ID**, **Sign-in URL (ACS)**, **Metadata URL** and **Logout URL**, plus a **Download metadata** button. For OpenID Connect you get **Redirect URL** and **Logout URL**. The link **Open the {provider} guide** takes you to the page for your provider in this documentation.
3. **Tell this site about your provider.** For SAML, choose **Metadata URL (recommended)**, **Paste metadata XML** or **Type them in**. For OpenID Connect, enter the **Issuer URL**, **Client ID**, **Client secret** and **Scopes**. The client secret must be an environment variable such as `$OKTA_CLIENT_SECRET`. Portero refuses a typed secret, so it never reaches your repository.
4. **Who gets in.** Set **Allowed email domains**, **Create accounts on first sign-in**, **Match existing accounts by email**, group rules, **Your provider already handles two-step sign-in** and **Longest session (hours)**.
5. **Test it.** Press **Test sign-in**. A new tab opens your provider. Portero then shows what the provider sent and what would happen. A test changes nothing on your site. When it passes, press **Finish setup**.

A connection's handle (for example `okta`) is part of its web addresses. It locks after the first save, so choose it with care.

### Who gets in, in more detail

Portero looks for the person's account in this order.

1. A link already exists for this connection and this person. That user signs in.
2. If **Match existing accounts by email** is on, the one user with that email (compared in lowercase) is linked. This needs **Allowed email domains**. An admin account is refused with `admin_link_blocked` unless **Allow matching admin accounts** is also on. Leave that off unless you are sure. Anyone who controls the provider could otherwise become an admin.
3. If **Create accounts on first sign-in** is on, a new account is created. New accounts have no password.
4. Otherwise the sign-in is refused with `no_account`.

People are identified by the provider's own stable ID (SAML NameID, or OpenID Connect `iss` plus `sub`), never by email. So a change of email at the provider doesn't create a second account.

### Group rules

On Craft Pro, **Group rules** read "If groups include {value}, add to {Craft group}". At each sign-in, Portero adds and removes only the Craft groups that your rules name. Other groups are never touched.

Two more settings sit below the rules:

- **Match group names to Craft group handles.** A group from your provider joins the Craft group with the same handle, with no rule. It's quicker to set up, but anyone who can edit groups at your provider can then give access here.
- **Let {provider} manage all groups.** Removes people from every Craft group that their rules and defaults leave out. Break-glass accounts keep theirs. **Default groups** apply to everyone on the connection. **Refuse sign-in if no rule matches** refuses people who match no rule (`no_group_match`). The **Admin rule** makes people admins when the provider sends a given group. It is off when empty. Use it with care.

## Sign-in rules

Open **Sign-in rules**.

- **Who must use single sign-on?** Nobody (the default), or **Everyone except the accounts below**. When it is on, passwords and passkeys are refused for everyone but the break-glass accounts.
- **Break-glass accounts.** Admins who can always use a password. Keep at least one, so a provider outage can't lock you out.
- **Send everyone straight to the provider.** With exactly one enabled connection, the login page goes straight to the provider. Add `?portero=skip` to the login address to see the normal form.
- **Keep login history for (days).**

You can't turn the "everyone" rule on until a connection has passed a test, you have listed at least one break-glass admin, and that admin has a password.

If you get locked out, see [Troubleshooting](troubleshooting.md#lockout).

## Deploying with project config and .env

Connections and sign-in rules are stored in Craft's project config, so they deploy like any other setting. Make changes on a site where `allowAdminChanges` is on, then deploy. Where it is off, the screens are read-only and say so.

Secrets never go in project config. Put them in the environment of every site, and refer to them by name:

```
OKTA_CLIENT_SECRET=...
```

Then enter `$OKTA_CLIENT_SECRET` in the **Client secret** field.

Two things are not in project config, because they change on production:

- The date of the last passed test. Press **Test sign-in** once on each environment you care about.
- Sign-in history and the links between people and provider accounts. These live in the database.

A typical flow:

1. Build and test the connection on staging.
2. Deploy the project config.
3. Add the secrets to the production environment.
4. Press **Test sign-in** on production.

## Console commands

Run these with `php craft`.

| Command | What it does |
|---|---|
| `portero/rules/off` | Turns the "who must use single sign-on" rule off, so passwords work for everyone. It works when every provider is down. If `config/project` is in your repository, make the same change there too, or the next deploy turns the rule back on. |
| `portero/keys/generate <handle>` | Creates a key pair for signed requests and encrypted assertions on one connection. It writes `PORTERO_<HANDLE>_SP_KEY` and `PORTERO_<HANDLE>_SP_CERT` to `.env` and saves the references on the connection. Copy both values to every environment. |
| `portero/keys/generate <handle> --print` | Prints the two values instead of writing `.env`. Use it where `.env` isn't a file, such as Craft Cloud. |
| `portero/metadata/refresh` | Refreshes the metadata of every connection that has a metadata URL. A failed fetch keeps the last good copy. |
| `portero/doctor` | Prints the health of every connection and of the sign-in rules. It exits with a non-zero code if anything needs attention, so you can use it in monitoring. |
| `portero/import/saml-sp` | Brings providers and linked people across from the plugin SAML SSO Service Provider. See [Switching](switching.md). |
| `portero/import/saml-sp --dry-run` | Shows what the import would do and writes nothing. |

A daily queue job also refreshes metadata. It needs a working Craft queue runner. Portero shows a control panel alert 30 days and again 7 days before a certificate expires.

## Signed requests and encrypted assertions

Both are optional. To use them:

1. Run `php craft portero/keys/generate <handle>`.
2. On step 3, open **Signing and encryption**. Turn on **Sign requests** or **Encrypted assertions**.
3. Download the new metadata from step 2 and give it to your provider.

If you sign requests, choose a **NameID format**. Portero refuses to save signing without one, because some providers (Okta) need it.

## On the front end

Send visitors to a connection from any template:

```twig
<a href="{{ craft.portero.loginUrl('okta', '/members') }}">Sign in with Okta</a>
```

The first argument is the connection's handle. The second is where the person should land. It must be a path on your own site. Anything else (such as `//example.com` or `https://example.com`) falls back to the post-login page.

The control panel login page gets a button for each enabled connection automatically. Turn off **Show on the control panel login page** on step 1 to leave one out. To draw your own buttons, see [For developers](developers.md).

Sign-in starting at the provider (a tile in the provider's dashboard) is off by default. It is a per-connection setting called **Allow sign-in started at the provider**, under **Attributes and advanced settings** on step 4. It carries more risk, because the sign-in didn't start in this browser on this site.

## PORTERO_BASE_URL

The addresses you give your provider are built from your site's base URL. If that is not the address people use, set `PORTERO_BASE_URL` in `.env`:

```
PORTERO_BASE_URL=https://cms.example.com
```

Use it when:

- You have several sites and want one fixed address.
- You set up the connection on a different domain than production. The addresses on step 2 show the base in use. If production differs, finish setup on production or set `PORTERO_BASE_URL`.
- **Your control panel is on a separate domain.** Sessions belong to a host, so set `PORTERO_BASE_URL` to the control panel's address. If you don't, people may land back on the site signed out.

Addresses end in `/portero/...` and never change, so it is safe to give them to your provider. For example, `/portero/saml/acs/<handle>`.

## Several sites

See [Multi-site](multi-site.md).

## Headless sites

Portero works with headless setups. Start sign-in from your front end with a link to `/portero/login/<handle>` on the Craft host (or `craft.portero.loginUrl()` in a template). The Craft host receives the provider's reply, signs the person in and sends them to the return path you gave. Set `PORTERO_BASE_URL` to the Craft host.

## Metadata and discovery addresses

In production (`devMode` off), the metadata URL and the OpenID Connect issuer URL must be public HTTPS addresses. Portero won't fetch addresses that point to private networks. In `devMode` this check is relaxed so you can test with a local provider.

## Two-step sign-in

If your provider asks for a second step, turn on **Your provider already handles two-step sign-in** on step 4. People from that connection then skip Craft's own two-step setup. Password users on the same site still see it. Only you know whether your provider really asks for a second step, so this is off by default.

## Sessions and sign-out

- **Longest session (hours)** signs people out after that time, even if they're active. This is how an ex-employee's open session ends. Without the logout setting below, Portero doesn't receive a signal from your provider when an account closes.
- **Sign out of the provider too** signs the person out at the provider as well, where the provider supports it.
- **Sign out here when they sign out at {provider}** does the opposite: signing out at the provider ends the Craft session too. It's off by default, and it's under **Attributes and advanced settings** on step 4.

### Signing out at the provider

Turn the setting on, then give your provider the address from step 2.

| Method | Address | How it's protected |
|---|---|---|
| SAML | **Logout URL**, on the HTTP-Redirect binding | The request must be signed with your provider's certificate. SHA-1 is refused. Each request works once, and only for about 10 minutes |
| OpenID Connect | **Back-channel logout URL** | The provider's server sends a signed logout token. Each token works once |

Each logout ends only the session it names, and Login history records it. The person's next request here takes them to the login page. If you use SAML, your provider's **SingleLogoutService** address must be set on step 3, because Portero answers there.

Not every provider sends these:

| Provider | What to expect |
|---|---|
| Keycloak | Both work, and we tested both live. For SAML, fill in only the Redirect binding URL, not the POST one. See [the Keycloak guide](keycloak.md) |
| Microsoft Entra ID | Entra's OpenID Connect setting is a front-channel logout URL, which Portero doesn't use, and we found no Microsoft page offering back-channel logout. Its SAML apps have a logout setting, which may work. Not tested live |
| Okta | The Okta pages we read cover logout started by the app, not logout sent to it, so don't rely on this. Not tested live |
| Google Workspace | Google Workspace has no SAML single logout. Use **Longest session** |

Back-channel logout needs your provider's server to reach your site, so it won't work on a site that only a private network can see.

## Licensing

Portero SSO is sold through the Craft Plugin Store under Craft's licence (see `LICENSE.md`).

- Licences are perpetual. You can keep using the version you have forever.
- Updates are a yearly renewal. If you stop renewing, you keep what you have, but you get no more updates. That includes security fixes. Please renew if you rely on the plugin for sign-in.
- A licence problem never changes sign-in behaviour. Portero has no code that does.

## Support

- Email support@facilo.es, or open an issue on the plugin's GitHub page.
- We answer as soon as we can. We don't guarantee a response time.
- Never paste real SAML assertions, tokens, client secrets or private keys into an email or an issue. They can let someone sign in as your staff. The reason code from Login history, the connection's handle and the provider name are usually enough.
- To report a security problem, see [SECURITY.md](../SECURITY.md).
