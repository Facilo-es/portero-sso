# Security policy

## Report a problem privately

Please don't open a public issue for a security problem.

- Use GitHub's **private vulnerability reporting**: on the repository, open the **Security** tab and choose **Report a vulnerability**.
- Or email **support@facilo.es** with "Security" in the subject.

Tell us what you found, which version, and how to repeat it. Never send real assertions, tokens, client secrets or private keys. Test data is enough.

We read every report and answer as soon as we can. We don't promise a response time. If a report suggests a flaw is being actively exploited, we treat it as urgent.

## How fixes are released

1. We confirm the problem and prepare a fix on the `1.x` branch.
2. We release a patch version, for example 1.0.1, and describe the fix in `CHANGELOG.md`. We say who found it, if you want to be named.
3. We publish the details after a fix is available, so people have time to update.
4. Each release comes with a software bill of materials (CycloneDX).

Fixes come to you through the normal Craft update. A site must hold a current update licence to receive updates. Licences are perpetual, so a site that stops renewing keeps what it has, but gets no updates, including security fixes.

## Support period

Version 1.x (for Craft 5) gets security fixes for 5 years from the release of 1.0.0 (2 November 2026), or for as long as Craft 5 is supported, whichever ends first.

When that period ends, we will say so in the changelog. Only the latest 1.x release is supported.

## Scope

In scope: the Portero SSO plugin code in this repository.

Out of scope: Craft CMS itself, your identity provider, your server, and third-party libraries (report those to their authors, but tell us too if they affect Portero).

## No guarantee

We build Portero with care and publish a [risk assessment](docs/security/risk-assessment.md). We can't promise it is free of flaws. The software is provided as is, under the terms in `LICENSE.md`.
