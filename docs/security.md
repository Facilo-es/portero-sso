# Security

This page says in plain words how Portero protects sign-in, and what it stores. It is not a guarantee. No software can promise to be free of flaws. For the formal view, see the [risk assessment](security/risk-assessment.md).

## How sign-in is protected

- **Signed assertions are always required** for SAML. This can't be turned off. A signed response alone is not enough. SHA-1 signatures are refused.
- **OpenID Connect uses the Authorization Code flow with PKCE (S256).** Implicit and hybrid flows aren't offered. Tokens must use RS256 or ES256. Unsigned tokens and HMAC tokens are refused. Portero checks `iss`, `aud`, `azp`, `exp`, `iat`, `nonce` and `state`.
- **Every sign-in is single use and lasts 10 minutes.** Each assertion ID is remembered until it expires, so a copied response can't be used twice.
- **Each sign-in is tied to the browser that started it**, through a random cookie named `portero_bind`.
- **People are identified by the provider's stable ID**, not by email. An email is only used to find an account when no link exists, and only when you allow it.
- **Domain checks are exact.** `evil-acme.com` and `acme.com.evil.io` don't match `acme.com`.
- **Admin accounts aren't matched by email** unless you turn that on.
- **Return addresses must be paths on your own site.** Addresses such as `//example.com` or `https://example.com` fall back to the post-login page.
- **Secrets stay out of your repository.** Client secrets and private keys accept only an environment variable name. A typed secret is refused.
- **Metadata and discovery addresses must be public HTTPS in production.** Portero refuses addresses that point at private networks.
- **No inline scripts** in Portero's own screens.
- **Provider values are escaped** wherever they are shown.
- **Craft's user limit is never worked around.** Suspended users are never signed in.
- **Sessions can have a hard limit** (**Longest session (hours)**). That is how an ex-employee's open session ends, because the provider doesn't tell us when an account closes.
- **A lockout guard** and the command `php craft portero/rules/off` keep a provider outage from locking you out.
- **A licence problem never changes sign-in.**

Craft's own login page has inline scripts. A strict Content Security Policy without `unsafe-inline` may stop Craft's login page from working, with or without Portero. Portero doesn't promise strict-CSP compatibility on that page.

## Logout started by the provider

This is off by default on each connection. When it's on:

- SAML logout requests must be signed with the provider's certificate, on the HTTP-Redirect binding, so nobody can sign a person out by sending them a link. SHA-1 is refused. A request works once, and is refused after about 10 minutes.
- OpenID Connect logout tokens pass the same signature, issuer and audience checks as sign-in tokens, must carry the logout event, and can't carry a nonce, so a sign-in token can't pass as one. Each works once.
- Only the session named is ended. If the provider names a person and no session, all of that person's sessions on this connection end.
- Craft's session rows go too, including older ones from sessions restored from the identity cookie, so no copy of that cookie can bring the person back.
- Portero stores a hash of the person's provider ID and of the provider's session ID, tied to Craft's session token. It stores no email or name here. Rows go once the session has ended and 30 days have passed.

## What is stored, and what isn't

Stored in your site's database:

| What | Why | How long |
|---|---|---|
| Links between a person's Craft account and their provider ID | So the same person gets the same account | Until you delete the connection, or the user |
| Login history: time, connection, protocol, stage, outcome, reason code, Craft user ID if known, and a **masked email** such as `el***@example.com` | So you can see why a sign-in failed | 30 days or 2,000 rows, whichever is less. You can shorten the days. Craft's garbage collection removes older rows |
| Short-lived sign-in state (random values, a hash of the browser cookie) | To finish one sign-in | 10 minutes |
| Hashes of used assertion IDs | To refuse replays | Until the assertion would have expired |
| The date of the last passed test sign-in | For the lockout guard | Until replaced |

Stored in project config: connection settings, and references to environment variables. Never the secrets themselves.

**Not stored:** IP addresses, the SAML assertion, ID tokens, access tokens, passwords, or full email addresses in history. The result of **Test sign-in** is shown once and not saved, because it can hold personal data.

Craft itself stores the user account (name, email) when an account is created or updated by a sign-in. That is the normal Craft user data on your site.

## Privacy and GDPR

You, the site owner, are the controller of your staff's names and emails on your own site. Portero runs inside your Craft install. It makes no call home and sends nothing to us, and we have no access to your data. The only network requests it makes are to your own identity provider (metadata, discovery, keys, token exchange).

You are responsible for your legal basis, your privacy notice and your retention choices. Portero helps by masking emails in history, keeping no IP address and deleting history after a short time.

Support emails do reach us. Please never paste real assertions, tokens or secrets in them.

## EU Cyber Resilience Act

This is a neutral note and not legal advice. The EU Cyber Resilience Act (Regulation 2024/2847) lists single sign-on software among products with stricter rules. Reporting duties apply since 11 September 2026. The main rules apply from 11 December 2027.

What we do now:

- **Support period.** The 1.x series gets security fixes for 5 years from the 1.0.0 release, or for as long as Craft 5 is supported, whichever ends first. See [SECURITY.md](../SECURITY.md).
- **Reporting.** Report vulnerabilities privately through GitHub private vulnerability reporting or support@facilo.es. See [SECURITY.md](../SECURITY.md).
- **Security file.** This repository holds a written [risk assessment](security/risk-assessment.md).
- **Future.** Our plans for the main rules from December 2027 are not decided. If we change how the plugin is sold or supported, we will say so in the changelog and on the plugin page. Existing licences are perpetual and keep working.

Licences are perpetual, but updates come with the yearly renewal. If you stop renewing, you keep what you have, with no updates, including security fixes.

## Confirm your identity

Craft asks for a password before sensitive actions. People from a provider have none, so Portero adds **Confirm with {provider}**. It asks the provider for a fresh sign-in, and refuses a reply showing an older one.

For OpenID Connect the proof is the token's `auth_time`. For SAML it's the assertion's `AuthnInstant`, and not every provider reports the real sign-in time there: Keycloak gives the time of the assertion. Without signed requests, someone already holding both this site's session and the provider's session could then remove the forced sign-in from the request in their own browser. Turn on **Sign requests** on step 3 if your provider verifies them, to close that gap.

## Limits worth knowing

- A password session remembered by a browser ends when single sign-on becomes required, unless that person also has a provider link. Then it ends when it expires.
- A passed test stays valid when a connection's details change later. Test again after any change, and run `portero/doctor` in monitoring.
- With Craft's `httpProxy` setting on, the proxy looks up provider addresses, so Portero's address pinning doesn't apply.
