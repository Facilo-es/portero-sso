# Microsoft Entra ID

You can connect Microsoft Entra ID (formerly Azure AD) with SAML 2.0 or OpenID Connect. SAML is pre-selected in Portero.

Before you start, finish steps 1 and 2 of the Portero setup, so you have the addresses to copy. You need an Entra role that can add enterprise applications, such as Cloud Application Administrator.

## Option A: SAML

### In Entra

1. In the Microsoft Entra admin center, go to **Entra ID**, **Enterprise apps**, **New application**, **Create your own application**.
2. Choose **Integrate any other application you don't find in the gallery (Non-gallery)**. Give it a name.
3. Open **Single sign-on** and choose **SAML**.
4. In **Basic SAML Configuration**, press **Edit** and set:
   - **Identifier (Entity ID)**: the **Entity ID** from Portero step 2.
   - **Reply URL (Assertion Consumer Service URL)**: the **Sign-in URL (ACS)** from Portero step 2.
   - **Logout Url**: the **Logout URL** from Portero step 2, if you want sign-out of the provider.
5. In **Attributes & Claims**, the default claims are the ones Portero's Entra preset expects:

   | What | Claim name |
   |---|---|
   | Email | `http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress` |
   | First name | `http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname` |
   | Last name | `http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname` |
   | Groups | `http://schemas.microsoft.com/ws/2008/06/identity/claims/groups` |

   The default **Unique User Identifier** (name ID) is the user principal name. That is stable and works. If **Email** is empty for someone, Entra won't send the email claim, so check that users have a mail address.
6. In **SAML Certificates**, copy the **App Federation Metadata Url**.
7. Under **Users and groups**, assign the people or groups who may sign in.

### In Portero

1. On step 3, choose **Metadata URL (recommended)** and paste the **App Federation Metadata Url**.
2. On step 4, set your rules.
3. On step 5, press **Test sign-in**.

### Signing

Entra signs the assertion by default when you use the default settings. If you get `unsigned_assertion`, open **SAML Certificates** and check that **Signing Option** is **Sign SAML assertion** or **Sign SAML response and assertion**. Portero needs the assertion to be signed. Signing the response only is not enough.

### Groups: IDs, not names

Entra sends group object IDs (long values like `3f5a...`), not group names, unless you configure it otherwise. In Portero, write group rules with the ID:

> If groups include `3f5a1c2e-...`, add to Editors.

To send groups at all:

1. In **Attributes & Claims**, choose **Add a group claim**.
2. Choose **Groups assigned to the application**. This is recommended, and it keeps the claim small. The person must be a direct member of an assigned group. Nested groups aren't included with this option.
3. Under **Users and groups**, assign only the groups you actually need in Portero.

Cloud-only groups can be sent by display name if you pick the **Cloud-only group display names** source attribute (this works for groups assigned to the application). Group names are not unique in Entra, so IDs are safer.

### The too-many-groups marker (overage)

If a person is in too many groups, Entra sends no list. For SAML the limit is 150 groups. For OpenID Connect tokens it is 200. Instead it sends a pointer: in SAML, a claim named `http://schemas.microsoft.com/claims/groups.link`. In OpenID Connect, `_claim_names` or `hasgroups`.

When Portero sees this, it leaves the person's groups unchanged and logs a warning with the code `groups_overage` in Login history. To fix it, use **Groups assigned to the application** (above) and assign only the needed groups. If **Refuse sign-in if no rule matches** is on, the person is refused with `no_group_match`, because Portero can't tell which groups they have.

### Pitfalls

- **`wrong_audience`.** The **Identifier (Entity ID)** in Entra must equal the **Entity ID** in Portero.
- **`wrong_destination`.** The **Reply URL** must equal the **Sign-in URL (ACS)** exactly.
- **People can't sign in.** They must be assigned to the app (**Users and groups**), unless assignment is not required.
- **Claim names are long URLs.** If you changed them in Entra, change them in Portero under **Attributes and advanced settings** on step 4.

## Option B: OpenID Connect

### In Entra

1. Go to **Entra ID**, **App registrations**, **New registration**.
2. Under **Supported account types**, choose **Accounts in this organizational directory only (single tenant)**. Do not choose a multi-tenant option.
3. **Redirect URI**: choose **Web**, then paste the **Redirect URL** from Portero step 2.
4. After creating it, copy the **Application (client) ID** and the **Directory (tenant) ID** from **Overview**.
5. Under **Certificates & secrets**, create a **Client secret**. Copy the value now. Put it in your environment, for example `ENTRA_CLIENT_SECRET`.
6. For groups, under **Token configuration**, choose **Add groups claim**, then **Groups assigned to the application**. Then under **Enterprise applications**, assign only the groups you need.
7. In **Manage** under **Authentication**, you can add the **Logout URL** from Portero as the front-channel logout URL.

### In Portero

1. **Issuer URL**: `https://login.microsoftonline.com/<tenant-id>/v2.0`, with your tenant ID.
2. **Client ID**: the Application (client) ID.
3. **Client secret**: `$ENTRA_CLIENT_SECRET`.
4. **Scopes**: `openid email profile` is enough.
5. Press **Test sign-in**.

### Use your own tenant's issuer only

Portero refuses issuers that contain `common`, `organizations` or `consumers`. Those addresses let anyone with any Microsoft account reach your sign-in. Use your own tenant's address. Portero says: "This address lets anyone with a Microsoft account reach your sign-in. Use your own tenant's address."

### Pitfalls

- **Email missing.** The `email` claim is optional in Entra for OpenID Connect and isn't present for every account. If you get `missing_email`, check the user has a mail address, or add the `email` optional claim under **Token configuration**.
- **`oidc_exchange_failed`.** Client secret expired or the redirect URL differs. Entra secrets expire. Note the date.
- **`oidc_token_invalid`.** Tenant ID in the issuer is wrong.

## Addresses

In production (`devMode` off) the metadata and issuer addresses must be public HTTPS. Microsoft's are.

## What we tested

Microsoft Entra ID was **not tested live**. We tested with fixtures built from Microsoft's documented response shapes, including the group overage marker. We haven't signed in through a real Entra tenant. If something here doesn't match what you see, tell us at support@facilo.es, without real assertions or tokens.

Portero's SAML and OpenID Connect code was tested live against Keycloak, including signed requests, encrypted assertions and sign-out. SAML was also tested against SimpleSAMLphp. This gives no guarantee that Entra will behave the same way.
