# For developers

## Template helpers

| Helper | Returns |
|---|---|
| `craft.portero.loginUrl(handle, return)` | The sign-in address of one connection |
| `craft.portero.connections(return)` | The enabled connections for this site: `handle`, `label` and `url` |
| `craft.portero.identities(user)` | How this person signs in: `connection` and `lastSignIn` |

To sign out, use Craft's own `logoutUrl`. It also signs the person out of the provider when the connection has **Sign out of the provider too** on.

## Events

All five are on `facilo\portero\Craft\Portero`.

| Event | Class | Use it to |
|---|---|---|
| `EVENT_BEFORE_PROVIDER_REQUEST` | `ProviderRequestEvent` | Add query parameters to the request sent to the provider, such as `login_hint` or `domain_hint`. On OpenID Connect they can't replace `state`, `nonce` or anything else Portero sets |
| `EVENT_FIND_USER` | `FindUserEvent` | Match an account some other way than by email, by setting `$event->user`. Portero's checks for admins and suspended accounts still apply to whoever you pick |
| `EVENT_BEFORE_SYNC_USER` | `SyncUserEvent` | Change `addGroups` and `removeGroups`, or refuse the sign-in with `$event->isValid = false` |
| `EVENT_AFTER_SYNC_USER` | `SyncUserEvent` | Do more with the saved account |
| `EVENT_BEFORE_REDIRECT` | `RedirectEvent` | Change `$event->url`, where the person goes after signing in. This value isn't checked, so never build it from request data |

```php
use facilo\portero\Craft\Portero;
use facilo\portero\Craft\events\SyncUserEvent;
use yii\base\Event;

Event::on(Portero::class, Portero::EVENT_BEFORE_SYNC_USER, function (SyncUserEvent $event) {
    if (str_ends_with((string) $event->identity->email, '@contractors.example.com')) {
        $event->addGroups[] = 'contractors';
    }
});
```

`$event->identity->providerSession` can hold the raw ID token, so never log or dump it.

An event listener runs with full trust. It can give someone more access than your rules would, so keep it small. `FindUserEvent` runs before an account is linked: anything it returns becomes a permanent link.
