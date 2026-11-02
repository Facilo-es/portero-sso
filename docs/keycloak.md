# Keycloak

You can connect Keycloak with SAML 2.0 or OpenID Connect. SAML is pre-selected in Portero.

Before you start, finish steps 1 and 2 of the Portero setup. In Keycloak, work in the realm your staff belong to. Replace `keycloak.example.com` and `myrealm` below with your own.

## Option A: SAML

### In Keycloak

1. Go to **Clients** and choose **Create client**.
2. **Client type**: **SAML**. **Client ID**: the **Entity ID** from Portero step 2. Press **Next**.
3. **Valid redirect URIs**: the **Sign-in URL (ACS)** from Portero step 2. Press **Save**.
4. On the **Settings** tab:
   - **Name ID format**: **email** or **persistent**. Turn on **Force name ID format** if you want to be sure.
   - **Sign documents**: on (the default).
   - **Sign assertions**: **turn this on**. See below.
   - **Signature algorithm**: **RSA_SHA256**.
5. On the **Client scopes** tab, open the dedicated scope for this client and add these mappers (**Add mapper**, **By configuration**):

   | Mapper type | Name | Attribute | SAML attribute name |
   |---|---|---|---|
   | User Property | email | `email` | `email` |
   | User Property | firstName | `firstName` | `firstName` |
   | User Property | lastName | `lastName` | `lastName` |
   | Group list | groups | | `groups` |

   For the group list mapper, turn **Full group path** off if you want plain group names, such as `web-editors`. If it is on, Keycloak sends `/web-editors`, and your rule must use that value.
6. Your realm's metadata is at `https://keycloak.example.com/realms/myrealm/protocol/saml/descriptor`.

### Keycloak signs only the response by default

This is the most common problem. Keycloak's default signs the whole response but **not the assertion inside it**. Portero requires a signed assertion and refuses anything else with `unsigned_assertion`. Fix it by turning on **Sign assertions** in the client's Settings tab. You can keep **Sign documents** on as well. A signed response alone is never enough.

### In Portero

1. On step 3, choose **Metadata URL (recommended)** and enter the descriptor address above.
2. On step 4, set your rules. Keycloak's preset expects `email`, `firstName`, `lastName` and `groups`.
3. Press **Test sign-in** on step 5.

### Optional: signed requests and encrypted assertions

1. Run `php craft portero/keys/generate <handle>`.
2. In Portero, turn on **Sign requests** or **Encrypted assertions** under **Signing and encryption** on step 3, and download the new metadata from step 2.
3. In Keycloak, import it with **Keys** tab, or the client's **Import** action. For signed requests, turn on **Client signature required** and import the certificate. For encrypted assertions, turn on **Encrypt assertions** and import the certificate.

## Option B: OpenID Connect

### In Keycloak

1. Go to **Clients**, **Create client**. **Client type**: **OpenID Connect**. Give it a **Client ID**. Press **Next**.
2. Turn **Client authentication** on. Keep **Standard flow** on. Press **Next**.
3. **Valid redirect URIs**: the **Redirect URL** from Portero step 2. **Valid post logout redirect URIs**: the **Logout URL** from Portero step 2. Press **Save**.
4. On the **Credentials** tab, copy the **Client secret**. Put it in your environment, for example `KEYCLOAK_CLIENT_SECRET`.
5. For groups, on the **Client scopes** tab, open the dedicated scope and add a mapper **By configuration**, type **Group Membership**. Set **Token Claim Name** to `groups`. Turn **Full group path** off for plain names. Keep **Add to ID token** on.

### In Portero

1. **Issuer URL**: `https://keycloak.example.com/realms/myrealm`
2. **Client ID**: the one you chose.
3. **Client secret**: `$KEYCLOAK_CLIENT_SECRET`.
4. **Scopes**: `openid email profile`.
5. Press **Test sign-in**.

Portero reads `https://keycloak.example.com/realms/myrealm/.well-known/openid-configuration` and accepts RS256 or ES256 tokens.

## Signing out at Keycloak

Signing out at Keycloak can end the Craft session too.

For SAML: in the client's **Settings**, set **Logout Service Redirect Binding URL** under **Fine Grain SAML Endpoint Configuration** to the **Logout URL** from Portero step 2, and leave **Logout Service POST Binding URL** empty. Keycloak uses POST whenever that field is filled, and Portero doesn't accept POST. Leave **Front channel logout** on and **Sign documents** on. In Portero, the provider's logout address must be set on step 3, and turn on **Sign out here when they sign out at Keycloak** on step 4.

For OpenID Connect: in the client's **Settings**, set **Backchannel logout URL** to the **Back-channel logout URL** from Portero step 2, then turn on the same setting in Portero. Keycloak's server must be able to reach your site.

Keycloak writes its issuer from the address it was reached on. If it answers on more than one address, set its hostname, or the logout token's issuer won't match and Portero will refuse it.

## Pitfalls

- **`unsigned_assertion`.** Turn on **Sign assertions** (above).
- **`weak_algorithm`.** Use RSA_SHA256 and not RSA_SHA1.
- **`transient_nameid`.** Keycloak is not sending a stable ID. Use the **email** or **persistent** name ID format.
- **`wrong_destination`.** **Valid redirect URIs** must hold the exact **Sign-in URL (ACS)**.
- **No groups.** Check the mapper is in the client's dedicated scope, and that people are members of groups.
- **`oidc_token_invalid`.** The issuer has to match exactly, including the realm path. Check for a trailing slash.
- **Local test servers.** Metadata and discovery addresses must be public HTTPS in production. In `devMode` a local address is allowed.

## What we tested

Keycloak was **tested live** with Keycloak 26. We tested both SAML and OpenID Connect, including signed requests, encrypted assertions and sign-out, on Craft 5. The menu names above are Keycloak 26's. Other Keycloak versions may use different names. This gives no guarantee that your Keycloak setup will behave the same way.

Signing out at Keycloak was tested live on both methods, on Keycloak 26: the signed SAML request and the back-channel logout token each ended the right session and left another person's alone, and a session restored from Craft's identity cookie ended too.
