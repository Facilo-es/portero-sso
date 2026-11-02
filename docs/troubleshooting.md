# Troubleshooting

Every refused sign-in is recorded in **Login history** with a reason code. This page explains each code.

## How to read Login history

Open **Portero SSO**, then **Login history**. Each row shows:

- **When**: the time of the attempt.
- **Connection**: which connection. A **Test** tag marks a test sign-in.
- **Who**: the person's email, masked like `el***@example.com`. It can be empty if the failure came before the provider said who it was.
- **Result**: **Signed in**, **Signed out by {provider}**, **Refused** or **Warning**. Each has its own icon, so colour is not the only clue.
- **Why**: a plain explanation, the fix, and the reason code.

Use the two filters at the top to choose a connection, or only **Signed in** or **Refused**. Rows are kept for 30 days or 2,000 rows, whichever is less. You can change the days under **Sign-in rules**, **Keep login history for (days)**.

Your staff see only "We couldn't sign you in" and the code. You see the rest.

The **Test sign-in** button on step 5 of a connection shows the same explanations, with every attribute the provider sent. It is the fastest way to find a mapping problem.

## Reason codes

In the table, `{provider}` stands for the name of your provider.

| Code | What happened | How to fix it |
|---|---|---|
| `bad_signature` | The signature didn't match {provider}'s certificate | Refresh the metadata, or add the new certificate. The provider's certificate changed or the response was altered. Run `php craft portero/metadata/refresh`. With typed details, paste the new certificate. You can keep the old one beside it during a change. |
| `unsigned_assertion` | {provider} didn't sign the assertion | Turn on assertion signing in {provider}. Portero requires a signed assertion. A signed response alone is refused. Keycloak signs only the response by default, so turn on **Sign assertions**. |
| `weak_algorithm` | {provider} signed with SHA-1 | which is no longer safe', 'Switch to SHA-256 in {provider}. Portero refuses SHA-1. Choose RSA-SHA256 or stronger at the provider. |
| `expired_assertion` | The response was out of date | or the server clocks differ', "Check this server's clock. Time on this server and the provider differ by more than a few minutes. Turn on time sync (NTP) on the server. |
| `wrong_audience` | The response was meant for a different app | Check the Entity ID in {provider}. The **Entity ID** at the provider must equal the one on step 2, character for character. |
| `wrong_destination` | The response was sent to a different address | Check the Sign-in URL in {provider}. The ACS or reply URL at the provider must equal the **Sign-in URL (ACS)** on step 2, including `https` and any trailing slash. |
| `replayed` | This response had already been used | Sign in again. If it repeats, check Login history. Each response works once. A double click, the back button or a refresh sends it twice. Sign in again. If it keeps happening with no cause, look at the rows around it in Login history. |
| `unknown_request` | This site has no record of starting this sign-in | Start again from the login page. The sign-in didn't start here, or it took longer than 10 minutes. Start again from the login page. If the site has several servers, check they share the database. |
| `no_browser_binding` | The sign-in returned to a different browser | or cookies were blocked', 'Allow cookies and start again. Portero uses a `portero_bind` cookie that must come back on the provider's reply. Use HTTPS, allow cookies, and don't start in one browser and finish in another. |
| `idp_initiated_off` | Sign-in started at {provider} | which is off for this connection', 'Turn it on, or start from this site. The sign-in began at the provider's dashboard. Turn on **Allow sign-in started at the provider** (more risk), or start from your site's button. |
| `idp_error` | {provider} refused: {status} | Check the person's access in {provider}. The provider said no, for example the person isn't assigned to the app. Check their access at the provider. |
| `oidc_state` | The sign-in didn't match the one we started | Start again from the login page. The page was open too long, or two sign-ins were started at once. Close extra tabs and start again. |
| `oidc_nonce` | The token wasn't issued for this sign-in | Start again from the login page. The token belongs to a different sign-in. Start again from the login page. |
| `oidc_token_invalid` | The token failed a check: {detail} | Check the issuer and client ID. Login history shows which check failed: issuer, audience, expiry, signature or algorithm. The issuer must match exactly, and the client ID must be the one for this app. |
| `oidc_exchange_failed` | {provider} refused the code exchange | Check the client secret and redirect URL. Check the client secret (is the environment variable set on this server?) and that the redirect URL matches at the provider exactly. |
| `missing_email` | {provider} didn't send an email address | Add it to the attributes, or check the mapping. Add the `email` scope or attribute at the provider, or change the attribute name under **Attributes and advanced settings** on step 4. |
| `email_not_verified` | {provider} says this email isn't verified | Verify it in {provider}. Verify the email at the provider, then sign in again. |
| `domain_not_allowed` | This email's domain isn't on the allowed list | Add the domain, or check who's assigned. Add the domain to **Allowed email domains** on step 4, or remove the person from the app. The check is exact: `evil-acme.com` doesn't match `acme.com`. With Google, the `hd` claim is checked too. |
| `no_account` | No account exists | and creating one is off', 'Turn on account creation, or add the person. No link exists and **Create accounts on first sign-in** is off (or matching by email found nobody). Turn on account creation, add the person, or turn on matching by email with allowed domains. |
| `admin_link_blocked` | This email belongs to an admin | and matching admins is off', 'Sign in with a password and link it, or allow it. The email belongs to a Craft admin. Have that admin sign in with a password and link it, or turn on **Allow matching admin accounts** if you accept the risk. |
| `user_suspended` | This account is suspended in Craft | Unsuspend it if that's intended. Portero never signs in a suspended user. Unsuspend them in Craft if that is intended. |
| `user_limit` | This Craft edition has reached its user limit | Remove a user or upgrade Craft. Portero never works around Craft's user limit. Remove a user or upgrade Craft. |
| `no_group_match` | No group rule matched this person | Add a rule, or check the groups sent. **Refuse sign-in if no rule matches** is on and nothing matched. Add a rule, or check the group values on the test result page. With Entra, use group IDs. |
| `groups_overage` | Warning: Microsoft sent too many groups to list | Assign only the needed groups to the app. This is a warning, not a refusal. The person's groups were left unchanged. With Entra, use **Groups assigned to the application**. |
| `transient_nameid` | {provider} sends a temporary ID | which can't identify someone twice", 'Use a persistent or email NameID. Use a persistent or email name ID at the provider, or name an attribute under **Stable attribute** on step 3. |
| `already_signed_in` | Someone is already signed in on this browser | Sign out first. Sign out of Craft first, then use the button. |
| `connection_disabled` | This connection is turned off | Turn it on. Turn the connection on from the Connections page. |
| `not_fresh` | {provider} didn't ask this person to sign in again | Check that {provider} honours a forced sign-in. It shows when "Confirm with {provider}" gets back an older sign-in. |
| `certs_changed` | {provider}'s signing certificate changed with its latest details | Check that this was expected at {provider}. Sign-in carries on with the new certificate, so if nobody at {provider} changed it, treat it as a security issue. |
| `password_blocked` | A password was used where single sign-on is required | Use the sign-in button. The sign-in rules require single sign-on for this person. Use the sign-in button. Break-glass accounts are the exception. |
| `wrong_site` | This connection isn't turned on for this site | Add this site on step 1, or sign in from one of its sites. See [Multi-site](multi-site.md). |
| `logout_off` | The provider asked to sign someone out, which is off for this connection | Turn on **Sign out here when they sign out at {provider}** on step 4. For SAML, your provider's logout address must also be set on step 3. |
| `logout_invalid` | A logout request failed a check | Check that the provider signs its logout requests, and that the server clocks agree. For back-channel logout, Craft's log names the check that failed. |

## Lockout

You turned on **Everyone except the accounts below** and now nobody can sign in. Try these in order.

1. **Use the normal login form.** If **Send everyone straight to the provider** is on, add `?portero=skip` to the login address, for example `https://example.com/admin/login?portero=skip`. The usual password form appears. A break-glass account can sign in with its password.
2. **Use a break-glass account.** Break-glass accounts are admins listed under **Sign-in rules**. They can always use a password. Keep at least one, and make sure it has a password.
3. **Turn the rule off from the server:**

   ```
   php craft portero/rules/off
   ```

   Passwords work for everyone again. This works when the database is reachable, even if every provider is down. If `config/project` is in your repository, make the same change there, or the next deploy turns the rule back on.

Portero won't let you turn the rule on until a connection has passed a test, a break-glass admin is listed, and that admin has a password. Run `php craft portero/doctor` to check.

## Other things to check

- **The button is missing from the login page.** The connection is disabled, or Craft is older than 5.6. Portero adds buttons through Craft's login page hook.
- **Landed signed out on a separate control panel domain.** Set `PORTERO_BASE_URL` to the control panel's address.
- **First sign-in sends the person to Craft's two-step setup.** Turn on **Your provider already handles two-step sign-in** on step 4, if your provider asks for a second step.
- **Metadata won't load.** In production the address must be public HTTPS. Run `php craft portero/metadata/refresh` and `php craft portero/doctor` to see why.
- **Certificate alert.** Portero warns 30 days and 7 days before a certificate expires. Refresh the metadata or add the new certificate before then.

If you still need help, email support@facilo.es with the reason code, the connection's handle and the provider's name. Never include real assertions, tokens or secrets.
