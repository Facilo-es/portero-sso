# Okta

You can connect Okta with SAML 2.0 or OpenID Connect. SAML is pre-selected in Portero. Either works. Pick OpenID Connect if you prefer a client ID and secret to certificates.

Before you start, finish steps 1 and 2 of the Portero setup, so you have the addresses to copy. Keep that tab open.

You need an Okta admin account. Okta's exact menu names change now and then. If a label below has moved, look for the nearest match.

## Option A: SAML

### In Okta

1. In the Okta Admin Console, go to **Applications** and choose **Create App Integration**.
2. Choose **SAML 2.0** and press **Next**. Give the app a name.
3. In **Configure SAML**, fill in:
   - **Single sign-on URL**: the **Sign-in URL (ACS)** from Portero step 2. Okta doesn't allow underscores in this address.
   - **Audience URI (SP Entity ID)**: the **Entity ID** from Portero step 2.
   - **Name ID format**: choose **EmailAddress** or **Persistent**. Okta's default, Unspecified, also works, but see the warning below if you sign requests.
   - **Application username**: **Email** is a good choice.
4. Under **Attribute Statements**, add these. Name format can stay **Unspecified**.

   | Name | Value |
   |---|---|
   | `email` | `user.email` |
   | `firstName` | `user.firstName` |
   | `lastName` | `user.lastName` |

5. If you want group rules, add a **Group Attribute Statement** with the name `groups`. Choose a filter, for example **Matches regex** `.*` to send all groups, or **Starts with** `web-` to send only some. Send only the groups you need.
6. Finish the wizard. Under **Assignments**, assign the people or groups who may sign in.
7. Go to the **Sign On** tab. Copy the **Metadata URL**.

### In Portero

1. On step 3, choose **Metadata URL (recommended)** and paste the address.
2. On step 4, set your rules. Portero's Okta preset already expects `email`, `firstName`, `lastName` and `groups`. You can see and change these under **Attributes and advanced settings**.
3. On step 5, press **Test sign-in**.

### Signed requests: read this first

Okta has a **Signed Requests** setting. If you turn it on:

- Okta requires the sign-in request to include a NameID format, and it must match the **Name ID format** in Okta. An empty one gives an error and sign-in goes to the Okta dashboard.
- In Portero, open **Signing and encryption** on step 3 and set **NameID format** to the same format as in Okta. Portero refuses to save signed requests with no format.
- Okta then reads the sign-in URL from the request itself. **It deletes the URLs you typed in Okta.** That is normal. Portero always sends its address in the request, so sign-in still works.
- Run `php craft portero/keys/generate <handle>`, turn on **Sign requests**, and upload the new certificate to Okta under the app's **Signature Certificate**. Okta shows the **Signed Requests** checkbox only after you upload one.

If you don't need signed requests, leave the setting off. This is the simplest path.

### Pitfalls

- **Sign-in goes to the Okta dashboard, not your site.** Usually a NameID format mismatch (see above) or the person isn't assigned to the app.
- **`wrong_audience`.** The **Audience URI** in Okta must equal the **Entity ID** in Portero exactly.
- **`wrong_destination`.** The **Single sign-on URL** in Okta must equal the **Sign-in URL (ACS)** in Portero exactly. Check `http` against `https` and a trailing slash.
- **`weak_algorithm`.** Choose SHA-256 for the signature algorithm in Okta's advanced SAML settings.
- **`unsigned_assertion`.** In Okta's advanced SAML settings, set **Assertion Signature** to **Signed**. Portero always requires a signed assertion.
- **Tile in the Okta dashboard.** Starting at Okta is off by default. Either start from your site, or turn on **Allow sign-in started at the provider** for this connection and accept the extra risk.

## Option B: OpenID Connect

### In Okta

1. Go to **Applications** and choose **Create App Integration**.
2. Choose **OIDC - OpenID Connect**, then **Web Application**. Press **Next**.
3. Give it a name. Under **Grant type**, keep **Authorization Code**.
4. **Sign-in redirect URIs**: paste the **Redirect URL** from Portero step 2.
5. **Sign-out redirect URIs**: paste the **Logout URL** from Portero step 2.
6. Under **Assignments**, choose who may sign in, then **Save**.
7. Copy the **Client ID** and **Client secret**. Put the secret in your environment, for example `OKTA_CLIENT_SECRET`.
8. For groups: on the app's **Sign On** tab, edit **OpenID Connect ID Token**. Keep the **Group claim type** on **Filter**. Keep the claim name `groups`. Choose a filter, such as **Matches regex** `.*` or **Starts with** `web-`.

### In Portero

1. On step 3, set **Issuer URL** to your Okta address, for example `https://your-company.okta.com`. This is the Okta "org" authorization server. If you use a custom authorization server, use its issuer, such as `https://your-company.okta.com/oauth2/default`.
2. Set **Client ID** and enter `$OKTA_CLIENT_SECRET` as the **Client secret**.
3. **Scopes**: Okta sends groups only when asked. Set the scopes to `openid email profile groups`.
4. Press **Test sign-in** on step 5.

### Pitfalls

- **No groups arrive.** Check that **Scopes** includes `groups` and that the group claim filter in Okta matches your group names. Okta sends groups in the ID token. Portero uses the code flow, which returns a full ID token.
- **`oidc_exchange_failed`.** The client secret, or the redirect URL, doesn't match. Both must be exactly as in Okta.
- **`oidc_token_invalid`.** The issuer or client ID is wrong. The issuer must match what Okta sends, including any `/oauth2/...` part.
- **`email_not_verified`.** Okta says the email isn't verified. Verify it in Okta.

## Addresses

The metadata URL and the issuer must be public HTTPS addresses in production (`devMode` off). Okta's are.

## What we tested

Okta was **not tested live**. We tested with fixtures built from Okta's documented response shapes. The steps above follow Okta's current documentation, but we haven't signed in through a real Okta account. If something in this guide doesn't match what you see, please tell us at support@facilo.es, without real assertions or tokens.

Portero's SAML and OpenID Connect code was tested live against Keycloak, including signed requests, encrypted assertions and sign-out. SAML was also tested against SimpleSAMLphp. This gives no guarantee that Okta will behave the same way.
