# Any other OpenID Connect provider

Use this page for any provider that speaks OpenID Connect and isn't one of the named presets: Auth0, Authentik, Ping, OneLogin, Zitadel and others. In Portero, pick **Other OpenID Connect provider** on step 1.

## What your provider needs from you

Step 2 of the Portero setup shows these.

| Portero says | Providers often call it |
|---|---|
| **Redirect URL** | Redirect URI, Callback URL, Sign-in redirect URI |
| **Logout URL** | Post-logout redirect URI, Sign-out redirect URI |

Create a **web application** (a confidential client with a secret) that uses the **Authorization Code** flow. Portero always uses PKCE with S256. Implicit and hybrid flows aren't offered.

## What Portero needs from your provider

- **Issuer URL.** The address of the provider's discovery document without `/.well-known/openid-configuration`. Portero reads `<issuer>/.well-known/openid-configuration`. The `iss` in tokens must match the issuer exactly.
- **Client ID.**
- **Client secret.** Put it in your environment and enter `$MY_CLIENT_SECRET` in the field. Typed secrets are refused.
- **Scopes.** The default is `openid email profile`. Add `groups` if your provider sends groups only when asked.

## Claims Portero reads

| What | Claim |
|---|---|
| Email | `email` |
| First name | `given_name` |
| Last name | `family_name` |
| Groups | `groups` |

You can change these under **Attributes and advanced settings** on step 4.

- If the provider sends `email_verified: false`, the person is refused with `email_not_verified`.
- If there is no `email_verified` claim, the email is used only when **Allowed email domains** is set.
- Tokens must be signed with RS256 or ES256. Unsigned tokens and HMAC tokens are refused.
- Portero checks `iss`, `aud`, `azp`, `exp`, `iat`, `nonce` and `state`.
- Signing keys are cached for an hour. If the provider rotates keys, Portero refetches once (at most once a minute).

## Pitfalls

- `oidc_exchange_failed`: client secret or redirect URL don't match what the provider has.
- `oidc_token_invalid`: issuer or client ID is wrong, or the token failed a check. The reason is in Login history.
- `oidc_state` / `oidc_nonce`: the person used an old tab. Start again from the login page.
- `missing_email`: add the `email` scope, or fix the claim name.
- Microsoft's `common`, `organizations` and `consumers` issuers are refused. See the [Entra guide](entra.md).
- The issuer must be a public HTTPS address in production (`devMode` off).

Full list: [Troubleshooting](troubleshooting.md).

## What we tested

Portero's OpenID Connect code was tested live against Keycloak 26, with RS256 tokens and sign-out. ES256 tokens, key rotation and the token checks are covered by automated tests with fixtures. Other providers weren't tested live. This gives no guarantee that your provider will behave the same way.
