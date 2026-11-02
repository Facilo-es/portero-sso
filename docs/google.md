# Google Workspace

You can connect Google Workspace with SAML 2.0 or OpenID Connect. SAML is pre-selected in Portero.

Before you start, finish steps 1 and 2 of the Portero setup, so you have the addresses to copy. You need a super administrator account in Google Admin.

## Option A: SAML

### In Google Admin

1. Go to **Apps**, **Web and mobile apps**. Choose **Add App**, then **Add custom SAML app**. Give it a name and press **Continue**.
2. On the **Google Identity Provider details** page, press **Download metadata** (the IDP metadata file). Press **Continue**. Google doesn't give a metadata URL, so you will paste the XML into Portero later.
3. On **Service Provider details**, fill in:
   - **ACS URL**: the **Sign-in URL (ACS)** from Portero step 2. It must start with `https://`.
   - **Entity ID**: the **Entity ID** from Portero step 2.
   - **Name ID format**: **EMAIL** is a good choice.
   - **Name ID**: **Basic Information > Primary email**.
   - Leave **Signed response** off unless you want it. By default Google signs the assertion, which is what Portero needs.
4. On **Attribute mapping**, press **Add mapping** and map these. The **App attribute** names are the ones Portero's Google preset expects.

   | Google Directory attribute | App attribute |
   |---|---|
   | Primary email | `email` |
   | First name | `firstName` |
   | Last name | `lastName` |

5. Under **Group membership (optional)**, press **Search for a group**, add the groups you want to use (up to 75), and set **App attribute** to `groups`.
6. Press **Finish**.
7. Turn the app on. Open the app, press **User access**, choose **On for everyone** (or per organizational unit), and **Save**. Google says changes can take up to 24 hours, though it is usually quicker.

### In Portero

1. On step 3, choose **Paste metadata XML** and paste the contents of the file you downloaded.
2. On step 4, set **Allowed email domains** to your Google Workspace domain. See the `hd` note below.
3. On step 5, press **Test sign-in**.

### Pitfalls

- **Names are blank.** The attribute mapping step was skipped, or the app attribute names differ from Portero's. Check **Attributes and advanced settings** on step 4.
- **Everyone gets the default groups.** The group mapping is missing or the group names differ. Google can send group email addresses or names, depending on setup. Write your Portero group rules with what you actually see on the test result page.
- **`wrong_audience` or `wrong_destination`.** The Entity ID or ACS URL in Google differs from Portero. Copy them again.
- **Nothing happens, or access denied.** The app is off for that person's organizational unit.
- **Certificates expire.** Google's SAML certificate expires. Portero shows a control panel alert before it does. Use `php craft portero/doctor` to check.

## Option B: OpenID Connect

### In Google Cloud

1. In the Google Cloud console, create or choose a project. Under **APIs & Services**, set up the **OAuth consent screen**. Choose **Internal** if you only want your own Workspace users.
2. Under **Credentials**, choose **Create credentials**, **OAuth client ID**, and **Web application**.
3. **Authorized redirect URIs**: the **Redirect URL** from Portero step 2.
4. Copy the **Client ID** and **Client secret**. Put the secret in your environment, for example `GOOGLE_CLIENT_SECRET`.

### In Portero

1. **Issuer URL**: `https://accounts.google.com`
2. **Client ID**: the one from Google.
3. **Client secret**: `$GOOGLE_CLIENT_SECRET`.
4. **Scopes**: `openid email profile`.
5. **Allowed email domains**: your Workspace domain.
6. Press **Test sign-in**.

Google's standard OpenID Connect doesn't send Workspace groups. For group rules, use SAML.

## The hd claim

With OpenID Connect, Google adds an `hd` claim to the ID token for Workspace accounts. It names the person's Workspace domain. Portero checks it against **Allowed email domains** whenever it is present and you have set allowed domains. If `hd` isn't on that list, the person is refused with `domain_not_allowed`, even if the email looks right. Personal Google accounts carry no `hd`, so the email domain check is what covers them. Always set **Allowed email domains** when you use Google.

## Addresses

In production (`devMode` off), the issuer must be a public HTTPS address. Google's is.

## What we tested

Google Workspace was **not tested live**. We tested with fixtures built from Google's documented response shapes. We haven't signed in through a real Google Workspace. If something here doesn't match what you see, tell us at support@facilo.es, without real assertions or tokens.

Portero's SAML and OpenID Connect code was tested live against Keycloak, including signed requests, encrypted assertions and sign-out. SAML was also tested against SimpleSAMLphp. This gives no guarantee that Google will behave the same way.
