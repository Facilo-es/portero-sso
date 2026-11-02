# Multi-site

Portero works on a Craft install with several sites. Each connection decides where it works, and which address your provider is told about. If you run one site, you can skip this page: none of these settings appear.

Craft's accounts are shared across sites. These settings decide where someone can sign in, not who exists. A person who can sign in on one site has the same Craft account on all of them.

So treat **Sites** as tidiness, not as a security boundary. It keeps the right buttons on the right sites. A determined visitor can still start from the control panel button, or name another site in a request header. To keep people out, use **Allowed email domains** and group rules.

## Where a connection works

On step 1, **Sites** lists every site. Leave **All sites** on, or pick the ones this connection is for.

- On the sites you pick, `craft.portero.connections()` lists the connection, and its sign-in address works.
- On any other site, the connection isn't listed, and its address refuses with `wrong_site`.
- The control panel isn't part of a site, so its button follows **Show on the control panel login page** on step 1 instead. Turn that off to keep a connection for the front end only.

## Which address your provider gets

Sessions belong to a host, so a sign-in has to finish on the host it started on. **Address given to your provider** has two choices.

**Follow the site in use** (the default). Addresses are built from whichever site the person is on. Use this if your sites share a domain, as in `example.com/en` and `example.com/es`, or if you add each site's addresses to your provider. It also honours `PORTERO_BASE_URL`.

**One site.** Every address uses that site, even when `PORTERO_BASE_URL` is set. Your provider gets a single set of addresses. Someone who arrives on another host is moved to that site's address first, so they end up signed in there. This suits a site kept just for the control panel.

Change this setting before you copy the addresses into your provider. Changing it later changes them.

## Different clients on one install

Add one connection per client, each limited to its own site:

| Connection | Sites | Address given to your provider |
|---|---|---|
| `acme-okta` | Acme | Acme |
| `globex-entra` | Globex | Globex |

## Templates

```twig
{% for connection in craft.portero.connections('/members') %}
  <a class="btn" href="{{ connection.url }}">{{ connection.label }}</a>
{% endfor %}
```

This lists the enabled connections for the current site, so one template serves them all.

## What we tested

On a real Craft install with two sites and a live Keycloak: both sites sharing a host, with a full sign-in on the second site's address. Moving to another host was tested up to the redirect, not through a full sign-in.
