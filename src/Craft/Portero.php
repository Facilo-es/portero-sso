<?php

namespace facilo\portero\Craft;

use Craft;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\Session as SessionHelper;
use craft\helpers\UrlHelper;
use facilo\portero\Core\AccessRules;
use facilo\portero\Core\Connection;
use facilo\portero\Core\Email;
use facilo\portero\Core\OidcBroker;
use facilo\portero\Core\Reason;
use facilo\portero\Core\ProviderLogout;
use facilo\portero\Core\Refused;
use facilo\portero\Core\Result;
use facilo\portero\Core\SamlBroker;
use facilo\portero\Core\SignIn;
use facilo\portero\Core\Urls;
use facilo\portero\Craft\assets\PorteroAsset;
use facilo\portero\Craft\events\ProviderRequestEvent;
use facilo\portero\Craft\events\RedirectEvent;
use facilo\portero\Plugin;
use yii\base\Component;

/** Joins the pure logic in Core to Craft. */
class Portero extends Component
{
    /** @event events\SyncUserEvent before a sign-in writes to the account */
    public const EVENT_BEFORE_SYNC_USER = 'beforeSyncUser';
    /** @event events\SyncUserEvent after the account and its groups are saved */
    public const EVENT_AFTER_SYNC_USER = 'afterSyncUser';
    /** @event events\FindUserEvent when a sign-in looks for an account by email */
    public const EVENT_FIND_USER = 'findUser';
    /** @event events\ProviderRequestEvent before someone is sent to the provider */
    public const EVENT_BEFORE_PROVIDER_REQUEST = 'beforeProviderRequest';
    /** @event events\RedirectEvent before someone is sent on after signing in */
    public const EVENT_BEFORE_REDIRECT = 'beforeRedirect';

    public const BIND_COOKIE = 'portero_bind';
    private const BIND_SECONDS = 900;
    private const HISTORY_ROWS = 2000;
    private const NOISE_ROWS = 500;
    private const CONFIRM_SECONDS = 120;
    private const AFTER_LOGOUT_KEY = 'portero.afterLogout';

    /** What the person's provider session needs to end, kept from just before Craft's logout clears it. */
    private ?array $logoutNote = null;

    /** The session someone is leaving for another account. */
    private ?array $beforeImpersonation = null;

    /** Craft's token from the identity cookie, kept from just before Craft replaces it. */
    private ?string $cookieToken = null;

    /** The site's own disable2fa, set while a trusted session's request has it switched on. */
    private ?bool $siteDisable2fa = null;

    public function urls(Connection $connection): Urls
    {
        return new Urls($this->baseUrl($connection), $connection->handle, $connection->saml['legacyAcsPath'] ?? null);
    }

    /** A connection tied to one site's address always uses it. Otherwise the address follows the site in use, as cookies do. */
    public function baseUrl(?Connection $connection = null): string
    {
        $site = $connection?->addressSite ? $this->siteBase($connection->addressSite) : null;
        return $site ?? rtrim(App::env('PORTERO_BASE_URL') ?: UrlHelper::baseSiteUrl(), '/');
    }

    public function siteBase(string $siteUid): ?string
    {
        $base = $siteUid !== '' ? Craft::$app->getSites()->getSiteByUid($siteUid, true)?->getBaseUrl() : null;
        return $base ? rtrim($base, '/') : null;
    }

    /** Sessions and the browser binding belong to one host, so a sign-in has to start on the address it will come back to. */
    public function onItsAddress(Connection $connection): bool
    {
        return !$connection->addressSite || parse_url($this->baseUrl($connection), PHP_URL_HOST) === Craft::$app->getRequest()->getHostName();
    }

    public function allowedHere(Connection $connection): bool
    {
        return $connection->sites === [] || in_array(Craft::$app->getSites()->getCurrentSite()->uid, $connection->sites, true);
    }

    /** The control panel isn't part of any one site, so its button works wherever the connection shows it. */
    public function allowedFor(Connection $connection, ?string $return): bool
    {
        return $this->allowedHere($connection) || ($connection->showOnCpLogin && $return === parse_url(UrlHelper::cpUrl('dashboard'), PHP_URL_PATH));
    }

    public function saml(): SamlBroker
    {
        return new SamlBroker(new SystemClock());
    }

    public function oidc(): OidcBroker
    {
        return new OidcBroker(new CraftHttp(), new CraftKeyCache(), new SystemClock());
    }

    public function signIn(): SignIn
    {
        $rules = Plugin::getInstance()->rules->all();
        return new SignIn(
            new DbFlows(),
            new DbReplays(),
            new DbHistory(),
            new SystemClock(),
            new AccessRules(new CraftUsers(), $rules['breakGlassUserUids'] ?? []),
            new CraftSession(),
            $this->saml(),
            $this->oidc(),
        );
    }

    public function providerLogout(): ProviderLogout
    {
        return new ProviderLogout(new DbReplays(), new DbHistory(), new DbSessions(), $this->saml(), $this->oidc());
    }

    public function start(Connection $connection, string $kind, ?string $return): string
    {
        $request = new ProviderRequestEvent(['connection' => $connection, 'kind' => $kind]);
        $this->trigger(self::EVENT_BEFORE_PROVIDER_REQUEST, $request);
        $started = $this->signIn()->begin(
            $connection,
            $this->urls($connection),
            $kind,
            $return,
            $this->binding(),
            Plugin::getInstance()->connections->spKeys($connection),
            $request->params,
        );
        $secure = $this->isSecure();
        setcookie($this->bindCookieName(), $started['binding'], [
            'expires' => time() + self::BIND_SECONDS,
            'path' => '/',
            'httponly' => true,
            'secure' => $secure,
            'samesite' => $secure ? 'None' : 'Lax',
        ]);
        return $started['url'];
    }

    public function binding(): ?string
    {
        $value = $_COOKIE[$this->bindCookieName()] ?? null;
        return is_string($value) ? $value : null;
    }

    /** The __Host- prefix stops a sibling subdomain from planting the cookie. Browsers accept it only over HTTPS. */
    private function bindCookieName(): string
    {
        return ($this->isSecure() ? '__Host-' : '') . self::BIND_COOKIE;
    }

    /** Behind a proxy that ends TLS, Craft can see plain HTTP while the browser is on HTTPS, so the public address counts too. */
    private function isSecure(): bool
    {
        return Craft::$app->getRequest()->getIsSecureConnection() || str_starts_with($this->baseUrl(), 'https://');
    }

    public function secret(Connection $connection): string
    {
        return (string) App::parseEnv($connection->oidc['clientSecret'] ?? '');
    }

    public function loginUrl(string $handle, ?string $return = null): string
    {
        $query = $return !== null ? '?' . http_build_query(['return' => $return]) : '';
        return $this->baseUrl(Plugin::getInstance()->connections->byHandle($handle)) . '/portero/login/' . rawurlencode($handle) . $query;
    }

    public function buttonLabel(Connection $connection): string
    {
        return $connection->buttonLabel !== '' ? $connection->buttonLabel : Craft::t('portero-sso', 'Sign in with {provider}', ['provider' => $connection->providerLabel()]);
    }

    /** @return array<int,array{handle:string, label:string, url:string}> what a front-end template needs to draw its own buttons */
    public function siteConnections(?string $return = null): array
    {
        $list = [];
        foreach (Plugin::getInstance()->connections->enabled() as $connection) {
            if ($this->allowedHere($connection)) {
                $list[] = ['handle' => $connection->handle, 'label' => $this->buttonLabel($connection), 'url' => $this->loginUrl($connection->handle, $return)];
            }
        }
        return $list;
    }

    /** @return Connection[] the connections shown on the control panel's login page */
    private function cpConnections(): array
    {
        return array_values(array_filter(Plugin::getInstance()->connections->enabled(), static fn(Connection $connection) => $connection->showOnCpLogin));
    }

    public function loginButtons(): string
    {
        $html = '';
        $return = parse_url(UrlHelper::cpUrl('dashboard'), PHP_URL_PATH);
        foreach ($this->cpConnections() as $connection) {
            // Craft 6 draws its own sign-in buttons with this component, so Portero's match them.
            $html .= Html::tag('craft-button', Html::encode($this->buttonLabel($connection)), ['href' => $this->loginUrl($connection->handle, $return), 'class' => 'w-full portero-login']);
        }
        return $html;
    }

    public function recordPasswordRefusal(User $user): void
    {
        (new DbHistory())->record([
            'connectionUid' => '',
            'protocol' => 'password',
            'stage' => SignIn::KIND_LOGIN,
            'outcome' => 'refused',
            'reasonCode' => 'password_blocked',
            'maskedEmail' => Email::mask($user->email),
            'userId' => (int) $user->id,
        ]);
    }

    public function forgetSession(): void
    {
        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            Craft::$app->getSession()->remove(CraftSession::SESSION_KEY);
            Craft::$app->getSession()->remove(CraftSession::PENDING_KEY);
        }
    }

    public function noteLogout(): void
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }
        $state = Craft::$app->getSession()->get(CraftSession::SESSION_KEY);
        $this->logoutNote = is_array($state) && !empty($state['provider']) ? $state : null;
    }

    /** Only when the person pressed sign out, on a connection with this setting on. A session limit never sends anyone to the provider. */
    public function signOutOfProvider(): void
    {
        $note = $this->logoutNote;
        $request = Craft::$app->getRequest();
        if ($note === null || $request->getAcceptsJson() || Craft::$app->controller?->action?->getUniqueId() !== 'users/logout') {
            return;
        }
        $connection = Plugin::getInstance()->connections->get($note['connection']);
        if ($connection === null || !$connection->signOutOfProvider) {
            return;
        }
        try {
            $urls = $this->urls($connection);
            $url = $connection->protocol === Connection::SAML
                ? $this->saml()->logoutUrl($connection, $urls, $note['provider'], Plugin::getInstance()->connections->spKeys($connection))
                : $this->oidc()->logoutUrl($connection, $urls->logout(), $note['provider']['idToken'] ?? null);
        } catch (\Throwable $e) {
            Craft::warning('Portero: could not start the provider sign-out: ' . get_class($e), __METHOD__);
            return;
        }
        if ($url === null) {
            return;
        }
        Craft::$app->getSession()->set(self::AFTER_LOGOUT_KEY, $request->getIsCpRequest()
            ? UrlHelper::cpUrl('login', ['portero' => 'skip'])
            : UrlHelper::siteUrl(Craft::$app->getConfig()->getGeneral()->getPostLogoutRedirect()));
        Craft::$app->getResponse()->redirect($url)->send();
        Craft::$app->end();
    }

    public function afterLogoutUrl(): string
    {
        $session = Craft::$app->getSession();
        $url = $session->get(self::AFTER_LOGOUT_KEY);
        $session->remove(self::AFTER_LOGOUT_KEY);
        return is_string($url) ? $url : UrlHelper::cpUrl('login', ['portero' => 'skip']);
    }

    /** Remembers that the provider confirmed this person. The provider's reply can arrive without Craft's session cookie, so the elevation happens on the next page. */
    public function rememberConfirmation(int $userId): void
    {
        Craft::$app->getCache()->set($this->confirmKey(), $userId, self::CONFIRM_SECONDS);
    }

    public function elevateFromConfirmation(User $user): bool
    {
        $cache = Craft::$app->getCache();
        $confirmed = $cache->get($this->confirmKey());
        $cache->delete($this->confirmKey());
        if ((int) $confirmed !== (int) $user->id) {
            return false;
        }
        $seconds = Craft::$app->getConfig()->getGeneral()->elevatedSessionDuration;
        if ($seconds !== 0) {
            SessionHelper::set(Craft::$app->getUser()->elevatedSessionTimeoutParam, time() + $seconds);
        }
        return true;
    }

    private function confirmKey(): string
    {
        return 'portero.confirm.' . hash('sha256', (string) $this->binding());
    }

    /** @return string[] the warnings every control panel page shows to admins */
    public function cpAlerts(): array
    {
        if (!Craft::$app->getUser()->getIsAdmin()) {
            return [];
        }
        $plugin = Plugin::getInstance();
        $alerts = [];
        foreach ($plugin->connections->enabled() as $connection) {
            $provider = Html::encode($connection->providerLabel());
            $check = Html::a(Html::encode(Craft::t('portero-sso', 'Check it')), UrlHelper::cpUrl("portero-sso/connections/{$connection->uid}/step/3"), ['class' => 'go']);
            $days = $plugin->connections->certificateDays($connection);
            if ($days !== null && $days <= 30) {
                $alerts[] = Craft::t('portero-sso', 'Portero: the {provider} certificate expires in {n} days. Sign-in will stop working then.', ['provider' => $provider, 'n' => max(0, $days)]) . ' ' . $check;
            }
            $own = $plugin->connections->ownCertificateDays($connection);
            if ($own !== null && $own <= 30) {
                $alerts[] = Craft::t('portero-sso', "Portero: this site's certificate for {provider} expires in {n} days. Sign-in will stop working then.", ['provider' => $provider, 'n' => max(0, $own)]) . ' ' . $check;
            }
            if ($plugin->connections->certificatesChangedRecently($connection->uid)) {
                $alerts[] = Craft::t('portero-sso', "Portero: {provider}'s signing certificate changed with its latest details. Check that this was expected.", ['provider' => $provider]) . ' ' . $check;
            }
            if ($plugin->connections->metadataFailed($connection->uid)) {
                $alerts[] = Craft::t('portero-sso', "Portero: {provider}'s details couldn't be updated. Sign-in still works with the last good copy.", ['provider' => $provider]) . ' ' . $check;
            }
        }
        return $alerts;
    }

    /** Runs once Craft has a session token, so a later logout from the provider can find this session. */
    public function sessionOpened(array $note): void
    {
        if (isset($note['track'])) {
            (new DbSessions())->open($note['connection'], $note['userId'], $note['track'][0], $note['track'][1]);
        }
        Craft::$app->getSession()->set(CraftSession::SESSION_KEY, $note);
    }

    public function noteSessionBeforeLogin(): void
    {
        $state = Craft::$app->getSession()->get(CraftSession::SESSION_KEY);
        $this->beforeImpersonation = is_array($state) ? $state : null;
    }

    /** Reads the token out of the identity cookie: [id, authKey, duration], where authKey is itself [token, null, agent]. */
    public function noteCookieToken(): void
    {
        $user = Craft::$app->getUser();
        $cookie = json_decode((string) Craft::$app->getRequest()->getCookies()->getValue($user->identityCookie['name']), true);
        $key = is_array($cookie) ? json_decode((string) ($cookie[1] ?? ''), true) : null;
        $this->cookieToken = is_array($key) && is_string($key[0] ?? null) ? $key[0] : null;
    }

    /**
     * A person restored from Craft's identity cookie arrives on a new session, without our note of when they signed in.
     * The database has it, so a lost session can't escape the longest-session limit.
     */
    public function afterCraftLogin(User $user, bool $cookieBased): void
    {
        if ($cookieBased && $this->cookieToken !== null) {
            (new DbSessions())->restored($this->cookieToken, (string) Craft::$app->getUser()->getToken());
            $this->cookieToken = null;
        }
        if (!$cookieBased && $this->beforeImpersonation !== null && Craft::$app->getUser()->getImpersonator() !== null) {
            // Taking over someone's account mustn't lift the limits on the session it started from.
            $this->sessionOpened($this->beforeImpersonation);
            $this->beforeImpersonation = null;
            return;
        }
        if (!$cookieBased) {
            // Craft's second step ends here, so a sign-in that waited for it picks up its note now.
            $session = Craft::$app->getSession();
            $pending = $session->get(CraftSession::PENDING_KEY);
            $this->forgetSession();
            if (is_array($pending) && ($pending['userId'] ?? null) === (int) $user->id) {
                $this->sessionOpened($pending);
            }
            return;
        }
        $rules = Plugin::getInstance()->rules;
        if (in_array($user->uid, $rules->all()['breakGlassUserUids'] ?? [], true)) {
            return;
        }
        $links = (new Query())->select(['connectionUid', 'lastSignIn'])->from('{{%portero_identities}}')->where(['userId' => $user->id])->orderBy(['lastSignIn' => SORT_DESC])->all();
        foreach ($links as $link) {
            if ($link['lastSignIn'] && (!$rules->isExempt($user) || Plugin::getInstance()->connections->get($link['connectionUid'])?->maxSessionHours)) {
                Craft::$app->getSession()->set(CraftSession::SESSION_KEY, [
                    'connection' => $link['connectionUid'],
                    'since' => (new \DateTime($link['lastSignIn'], new \DateTimeZone('UTC')))->getTimestamp(),
                ]);
                return;
            }
        }
    }

    /** Runs on every web request, and reads only the session of someone already signed in. */
    public function onWebRequest(): void
    {
        $request = Craft::$app->getRequest();
        if ($request->getIsConsoleRequest() || !Craft::$app->getIsInstalled()) {
            return;
        }
        $user = Craft::$app->getUser();
        if ($user->getIsGuest()) {
            $this->maybeSkipLoginPage();
            return;
        }
        $state = Craft::$app->getSession()->get(CraftSession::SESSION_KEY);
        if (!is_array($state)) {
            // A password session from before the rules were turned on, or anything else that got past the sign-in page.
            if (!Plugin::getInstance()->rules->isExempt($user->getIdentity()) && $user->getImpersonator() === null) {
                $this->endSession();
            }
            $this->maintain();
            return;
        }
        $connection = Plugin::getInstance()->connections->get($state['connection']);
        // A deleted connection may have been deleted because it can't be trusted, so its sessions go with it.
        if ($connection === null || ($connection->maxSessionHours && time() - $state['since'] >= $connection->maxSessionHours * 3600)) {
            $this->endSession();
        }
        // Costs one lookup per request, and only for people signed in through a connection that has this on.
        if ($connection->acceptProviderLogout && (new DbSessions())->isEnded((string) $user->getToken())) {
            $this->endSession();
        }
        if ($connection->trustProvider2fa) {
            $general = Craft::$app->getConfig()->getGeneral();
            $this->siteDisable2fa = $general->disable2fa;
            $general->disable2fa = true;
        }
        $this->maintain();
        if ($request->getIsCpRequest() && !$request->getIsActionRequest() && !$request->getAcceptsJson()) {
            $this->offerConfirmButton($connection);
        }
    }

    /** Trust covers this person's session, never a new login, so a password posted from it still meets Craft's second step. */
    public function restoreTwoStep(): void
    {
        if ($this->siteDisable2fa !== null) {
            Craft::$app->getConfig()->getGeneral()->disable2fa = $this->siteDisable2fa;
        }
    }

    private function endSession(): void
    {
        Craft::$app->getUser()->logout(false);
        Craft::$app->getSession()->remove(CraftSession::SESSION_KEY);
        Craft::$app->getSession()->setNotice(Craft::t('portero-sso', 'Please sign in again.'));
        Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('login'))->send();
        Craft::$app->end();
    }

    /** Control panel visits are what keep the daily metadata job going. */
    private function maintain(): void
    {
        if (Craft::$app->getRequest()->getIsCpRequest()) {
            Plugin::getInstance()->metadata->schedule();
        }
    }

    /** People signed in through a provider have no password to type into Craft's "Confirm your identity" box. */
    private function offerConfirmButton(Connection $connection): void
    {
        $view = Craft::$app->getView();
        $view->registerAssetBundle(PorteroAsset::class);
        $view->registerMetaTag([
            'name' => 'portero-confirm',
            'content' => UrlHelper::actionUrl('portero-sso/auth/confirm'),
            'data-label' => Craft::t('portero-sso', 'Confirm with {provider}', ['provider' => $connection->providerLabel()]),
        ]);
    }

    private function maybeSkipLoginPage(): void
    {
        $request = Craft::$app->getRequest();
        if (!$request->getIsCpRequest() || $request->getPathInfo() !== 'login' || $request->getQueryParam('portero') === 'skip') {
            return;
        }
        $enabled = $this->cpConnections();
        if (count($enabled) === 1 && Plugin::getInstance()->rules->all()['autoRedirect']) {
            Craft::$app->getResponse()->redirect($this->loginUrl($enabled[0]->handle, parse_url(UrlHelper::cpUrl('dashboard'), PHP_URL_PATH)))->send();
            Craft::$app->end();
        }
    }

    public function afterLoginUrl(Connection $connection, Result $result): string
    {
        $event = new RedirectEvent(['connection' => $connection, 'url' => $this->landing($connection, $result)]);
        $this->trigger(self::EVENT_BEFORE_REDIRECT, $event);
        return $event->url;
    }

    private function landing(Connection $connection, Result $result): string
    {
        if (Craft::$app->getSession()->get(CraftSession::PENDING_KEY)) {
            return UrlHelper::cpUrl('login', ['verify' => 1]);
        }
        if ($result->returnPath !== null) {
            // A return path starts at the root, and a site can live in a subfolder, so only its scheme and host are added.
            return preg_replace('#^(https?://[^/]+).*$#', '$1', $this->baseUrl($connection)) . $result->returnPath;
        }
        $user = Craft::$app->getUser()->getIdentity();
        return $user && $user->can('accessCp') ? UrlHelper::cpUrl('dashboard') : UrlHelper::siteUrl();
    }

    /** @return array<int,array{id:int, connection:string, lastSignIn:?\DateTime}> */
    public function identities(int $userId): array
    {
        $connections = Plugin::getInstance()->connections;
        $rows = (new Query())->select(['id', 'connectionUid', 'lastSignIn'])->from('{{%portero_identities}}')->where(['userId' => $userId])->orderBy(['lastSignIn' => SORT_DESC])->all();
        return array_map(static fn(array $row) => [
            'id' => (int) $row['id'],
            'connection' => $connections->raw($row['connectionUid'])['name'] ?? '–',
            'lastSignIn' => $row['lastSignIn'] ? new \DateTime($row['lastSignIn'], new \DateTimeZone('UTC')) : null,
        ], $rows);
    }

    /** The links on a person's page, for admins. */
    public function userSidebar(User $user): string
    {
        if (!$user->id || !Craft::$app->getUser()->getIsAdmin()) {
            return '';
        }
        $links = $this->identities((int) $user->id);
        if ($links === []) {
            return '';
        }
        Craft::$app->getView()->registerAssetBundle(PorteroAsset::class);
        return Craft::$app->getView()->renderTemplate('portero-sso/_user-links', ['links' => $links, 'redirect' => $user->getCpEditUrl()], \craft\web\View::TEMPLATE_MODE_CP);
    }

    /** @return array<int,array> newest first, each with its connection's name */
    public function history(int $limit, ?string $connectionUid = null, ?string $outcome = null): array
    {
        $query = (new Query())->from('{{%portero_attempts}}')->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])->limit($limit);
        if ($connectionUid) {
            $query->andWhere(['connectionUid' => $connectionUid]);
        }
        if ($outcome) {
            $query->andWhere(['outcome' => $outcome]);
        }
        $connections = Plugin::getInstance()->connections;
        $rows = $query->all();
        foreach ($rows as &$row) {
            $row['date'] = new \DateTime($row['dateCreated'], new \DateTimeZone('UTC'));
            $row['connection'] = $connections->raw((string) $row['connectionUid'])['name'] ?? ($row['protocol'] === 'password' ? Craft::t('portero-sso', 'Password') : '–');
        }
        return $rows;
    }

    /** Adds the plain explanation and fix to history rows. */
    public function explain(array $rows): array
    {
        $connections = Plugin::getInstance()->connections;
        foreach ($rows as &$row) {
            if ($row['reasonCode']) {
                $raw = $connections->raw((string) $row['connectionUid']);
                $provider = $raw ? Connection::fromArray((string) $row['connectionUid'], $raw)->providerLabel() : $row['connection'];
                $params = ['provider' => $provider, 'status' => '', 'detail' => ''];
                $row['explanation'] = Craft::t('portero-sso', Reason::explain($row['reasonCode']), $params);
                $row['fix'] = Craft::t('portero-sso', Reason::fix($row['reasonCode']), $params);
            }
        }
        return $rows;
    }

    public function collectGarbage(): void
    {
        $db = Craft::$app->getDb();
        // Rows with no person attached are the only ones a stranger can cause, so they get a cap of their own and can't push out real records.
        $noise = (new Query())->select('id')->from('{{%portero_attempts}}')->where(['maskedEmail' => null, 'outcome' => 'refused'])
            ->orderBy(['id' => SORT_DESC])->offset(self::NOISE_ROWS)->limit(1)->scalar();
        if ($noise) {
            $db->createCommand()->delete('{{%portero_attempts}}', ['and', ['<=', 'id', $noise], ['maskedEmail' => null, 'outcome' => 'refused']])->execute();
        }
        $days = (int) (Plugin::getInstance()->rules->all()['historyDays'] ?? Rules::DEFAULT_DAYS);
        $db->createCommand()->delete('{{%portero_attempts}}', ['<', 'dateCreated', Db::prepareDateForDb(new \DateTime("-$days days"))])->execute();
        $keep = (new Query())->select('id')->from('{{%portero_attempts}}')->orderBy(['id' => SORT_DESC])->offset(self::HISTORY_ROWS)->limit(1)->scalar();
        if ($keep) {
            $db->createCommand()->delete('{{%portero_attempts}}', ['<=', 'id', $keep])->execute();
        }
        $now = time();
        $db->createCommand()->delete('{{%portero_flows}}', ['<', 'expiresAt', $now])->execute();
        $db->createCommand()->delete('{{%portero_replay}}', ['<', 'expiresAt', $now])->execute();
        (new DbSessions())->collectGarbage();
    }
}
