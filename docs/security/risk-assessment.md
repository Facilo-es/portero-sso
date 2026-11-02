# Portero SSO: risk assessment

Written for version 1.0.0. It lists what we protect, what can go wrong, what Portero does about it, and what remains. It is our own assessment and is not a certification or a guarantee. It will be reviewed when features change and after any security report.

## Scope

Portero SSO is a Craft CMS 5 plugin. It lets people sign in to the Craft control panel and to front-end areas through SAML 2.0 or OpenID Connect. It runs inside the site owner's Craft install. It sends no data to the vendor.

## Assets

| Asset | Why it matters |
|---|---|
| Craft sessions and accounts, especially admin accounts | A bad sign-in gives access to the site and its data |
| Provider trust: signing certificates, issuer, client ID | Decide whose word Portero accepts |
| Client secrets and site private keys | Let an attacker impersonate the site to the provider, or read encrypted assertions |
| Link table between provider IDs and Craft users | Decides which Craft account a provider identity reaches |
| Login history | Holds masked emails and reason codes |
| Project config | Holds connection settings and rules |

## Threats, mitigations and residual risk

| # | Threat | Mitigation | Residual risk |
|---|---|---|---|
| 1 | Forged or altered SAML response | Signed assertion required always; the OneLogin php-saml library in strict mode does the XML signature check. Plugin code writes no signature logic. SHA-1 refused | A flaw in a third-party library or in XML handling could still exist |
| 2 | XML signature wrapping, comments in NameID, XXE | Tests for the standard wrapping variants, a NameID with an XML comment, and external entities | New attack variants may be found later |
| 3 | Forged OpenID Connect token | Only RS256 or ES256; `alg: none` and HMAC refused; `iss`, `aud`, `azp`, `exp`, `iat`, `nonce`, `state` checked; keys cached for an hour with a limited refetch | A compromised provider signing key |
| 4 | Replay of a captured response or code | Single-use flows (10 minutes), stored assertion IDs, PKCE, `state` and `nonce` | Short window if the database is unavailable to record use |
| 5 | Login CSRF or sign-in started by an attacker | Flow state tied to the browser through a random `portero_bind` cookie; provider-started sign-in off by default | If the setting is turned on, the sign-in didn't start in this browser. The setting says so |
| 6 | Open redirect through the return path | Only local paths allowed; four known bypass forms tested | New browser parsing quirks |
| 7 | Account takeover by email matching | Stable provider ID used first; matching needs allowed domains; exact domain match; admin accounts blocked unless allowed; unverified OpenID emails refused | If the provider lets people choose any email, or an admin turns on admin matching, takeover is possible. Documented |
| 8 | Someone who controls the provider becomes admin | Admin rule off by default; admin matching off by default | Whoever controls the provider is trusted by design |
| 9 | Server-side request forgery through metadata or discovery URLs | HTTPS and public addresses required in production; address checked and pinned for the request; redirects not followed | A DNS or network trick not covered by the check |
| 10 | Leaked secrets | Secrets accepted only as environment variables; none logged; key pairs created by a command | Environment files can still leak through the host |
| 11 | Disclosure of personal data in history | Masked email; no IP, assertion or token; 30 days or 2,000 rows; test results not stored; admin only | Craft user records hold full emails, as usual |
| 12 | Cross-site scripting through provider values | Provider values escaped; no inline scripts in Portero screens | A bug in a Craft component we use |
| 13 | Lockout after misconfiguration or provider outage | Lockout guard; break-glass accounts; `?portero=skip`; `portero/rules/off` | Server access needed for the last step |
| 14 | Departed staff keep access | Longest session setting; account closure at the provider stops new sign-ins; optional logout started by the provider | Most providers send no signal when an account closes, so an open session lasts until the limit or logout. SCIM is out of scope |
| 15 | Weak second step | Optional trust of the provider's two-step sign-in, off by default | The site owner must know that the provider really asks |
| 16 | Expired certificate causes an outage | Daily refresh, alerts at 30 and 7 days, `portero/doctor` | Needs a running queue and someone who reads alerts |
| 17 | Vulnerable dependencies | Few dependencies, all stable and mainstream; an SBOM with each release | Delay between a library fix and our release |
| 18 | Unsupported version | Stated support period in `SECURITY.md`; perpetual licences keep working | Users who stop renewing get no updates, including security fixes |
| 19 | Provider sends a temporary ID | Refused unless a stable attribute is set | Misconfigured stable attribute |
| 20 | Admin changes by someone with control panel access | Settings screens need admin; read-only where `allowAdminChanges` is off | A compromised admin account can change settings |

## Out of scope

SCIM, LDAP, acting as an identity provider, SAML artifact binding, SAML logout on the POST or SOAP bindings. Security of the identity provider itself, of Craft, and of the host server.

## Testing

Automated tests cover the cases above with signed fixtures. End-to-end tests ran against Keycloak 26 (SAML and OpenID Connect) and SimpleSAMLphp (SAML). Okta, Microsoft Entra ID and Google Workspace were not tested live. A written security review was done on the code before release.

## Review

Reviewed for 1.0.0 on 2026-11-02. We review it again when the plugin changes in a way that touches sign-in, after a vulnerability report, and at least once a year.
