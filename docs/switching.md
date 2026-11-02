# Switching from SAML SSO Service Provider

If you use the plugin SAML SSO Service Provider (by Flipbox), one command brings its providers and linked people into Portero. Your provider's settings stay as they are, and staff keep their accounts.

## What is kept

- **Entity ID.** Your provider keeps trusting the same one.
- **Old addresses.** The old plugin's sign-in (ACS) and login paths keep working as aliases, so nothing changes at the provider.
- **Linked people.** Each person already linked to a provider account stays linked, so they sign in to the same Craft account.
- **Provider details**, read from the old plugin's stored metadata, and the attribute name for groups if one was set.

## What is not kept

- **The old plugin's encryption key.** If the old plugin encrypted assertions, its key stays with it. Run `php craft portero/keys/generate <handle>`, turn on **Encrypted assertions** under **Signing and encryption** on step 3, and give your provider the new metadata.
- **People with no link yet.** Anyone who never signed in through the old plugin has no link. To let them in, set **Allowed email domains** and turn on **Match existing accounts by email** on step 4, or turn on **Create accounts on first sign-in**. Without either, they're refused with `no_account`.
- Group rules, default groups and other Portero settings aren't brought across. Set them on step 4.
- **Group allow and deny lists, and a NameID override.** The import warns if a provider had these, and doesn't bring them across. Someone they kept out could otherwise get in, so set group rules and **Refuse sign-in if no rule matches** on step 4 before you remove the old plugin.

## Steps

Run the import while the old plugin is still installed, because it reads the old plugin's tables.

1. **Back up your database.**
2. **Dry run.** Run it where admin changes are on (usually staging or local):

   ```
   php craft portero/import/saml-sp --dry-run
   ```

   It lists the connections it would create and how many people it would link. It writes nothing.
3. **Import:**

   ```
   php craft portero/import/saml-sp
   ```

   It's safe to run twice. A second run changes nothing.
4. **Check the connection.** Open **Portero SSO**. Press **Test sign-in** on each new connection. Step 4 will need your decisions about **Allowed email domains** and matching, as above.
5. **Remove the old plugin** once the test passes and a real sign-in works.
6. **Deploy and repeat on production.** Connections go to production through project config. The links between people and provider accounts live in the database, so run `php craft portero/import/saml-sp` on production too, while the old plugin is still there. Then test and remove the old plugin there.

If project config is read-only where you run it, the command says so. Run it where admin changes are on, deploy, then run it on the other site for the links.

## If something looks wrong

- "The old plugin's tables aren't here." You removed the old plugin already, or it was never installed. Restore a backup and run the import before removing it.
- A person is asked for a password or gets `no_account`. They have no link yet. See above.
- `bad_signature` or `wrong_audience`. Refresh the metadata, or check the **Entity ID** on step 2 is the one your provider has.

More in [Troubleshooting](troubleshooting.md).
