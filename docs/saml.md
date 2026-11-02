# Any other SAML provider

Use this page for any provider that speaks SAML 2.0 and isn't one of the named presets: ADFS, OneLogin, Auth0, JumpCloud, Ping, SimpleSAMLphp and others. In Portero, pick **Other SAML provider** on step 1.

## What your provider needs from you

Step 2 of the Portero setup shows these. Give them to whoever runs your provider, or paste them in yourself.

| Portero says | Providers often call it |
|---|---|
| **Entity ID** | Audience, Audience URI, SP Entity ID, Issuer |
| **Sign-in URL (ACS)** | Assertion Consumer Service URL, ACS URL, Reply URL, Single sign-on URL, Recipient, Destination |
| **Metadata URL** or **Download metadata** | SP metadata. Many providers can import it. |
| **Logout URL** | Single logout service URL (optional) |

Binding: the provider must send the response by **HTTP-POST** to the ACS address. Portero sends requests by HTTP-Redirect.

## Settings your provider must use

- **Sign the assertion.** Portero requires a signed assertion. A signed response alone is refused with `unsigned_assertion`. You can sign both.
- **Use SHA-256 or stronger.** SHA-1 is refused with `weak_algorithm`.
- **Send a stable name ID.** Use persistent, email address or unspecified (if it holds a stable value). A transient ID is refused with `transient_nameid`. If you can't change it, use **Stable attribute** under **Signing and encryption** on step 3 to name an attribute that never changes for a person.
- **Send these attributes.** Names depend on your provider. Portero's default expects:

  | What | Attribute name |
  |---|---|
  | Email | `email` |
  | First name | `firstName` |
  | Last name | `lastName` |
  | Groups | `groups` |

  You can change any of these under **Attributes and advanced settings** on step 4. If your provider uses long names like `http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress`, enter those.
- **Assign the people.** Make sure the people who should sign in have access to the app at the provider.

## In Portero

1. On step 3, add your provider's details. Choose one of:
   - **Metadata URL (recommended)**: lets Portero refresh the details daily and warns before a certificate expires.
   - **Paste metadata XML**: for providers with no public metadata address.
   - **Type them in**: **Provider Entity ID**, **Provider sign-in URL**, optional **Provider logout URL**, and **Signing certificates**. You can paste more than one certificate, one after another. This is useful during a certificate change.
2. On step 4, set who gets in.
3. On step 5, press **Test sign-in**.

## Optional: signed requests and encrypted assertions

1. Run `php craft portero/keys/generate <handle>`. Use `--print` where `.env` isn't a file.
2. On step 3, open **Signing and encryption**. Turn on **Sign requests** and/or **Encrypted assertions**. If you sign requests, also choose a **NameID format**, because Portero refuses to save signed requests without one.
3. Give your provider the new metadata from step 2.

## Pitfalls

- `bad_signature`: the certificate changed at the provider. Refresh the metadata (`php craft portero/metadata/refresh`) or add the new certificate.
- `expired_assertion`: this server's clock is wrong. Check it and use time sync.
- `wrong_audience` / `wrong_destination`: Entity ID or ACS URL differ from what the provider has. Compare them character by character.
- `no_browser_binding`: the return reached a different browser, or cookies were blocked. Portero sets a small `portero_bind` cookie that must come back on the provider's POST. Use HTTPS in production.
- Metadata URLs must be public HTTPS in production (`devMode` off).

Full list: [Troubleshooting](troubleshooting.md).

## What we tested

Portero's SAML code was tested live against Keycloak 26, including signed requests, encrypted assertions and sign-out. It was also tested live against SimpleSAMLphp. SimpleSAMLphp sends a transient name ID by default, so we used a stable attribute (`email`) there. Other providers weren't tested live. This gives no guarantee that your provider will behave the same way.
