<?php

namespace facilo\portero;

use Craft;
use craft\base\Element;
use craft\elements\User;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterCpAlertsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\Cp;
use craft\services\Gc;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use CraftCms\Cms\Auth\Enums\AuthError;
use CraftCms\Cms\Auth\Events\LoginUserRetrieved;
use CraftCms\Cms\Auth\Events\UserAuthenticating;
use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\View\TemplateHooks;
use CraftCms\Cms\Auth\OAuth\OAuth;
use facilo\portero\Craft\Connections;
use facilo\portero\Craft\LoginButtons;
use facilo\portero\Craft\console\Module as ConsoleModule;
use facilo\portero\Craft\Metadata;
use facilo\portero\Craft\Portero;
use facilo\portero\Craft\Rules;
use facilo\portero\Craft\TwigVariable;
use facilo\portero\Craft\WebModule;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event as Events;
use yii\base\Event;

/**
 * Craft 6 plugin. Sign-in, sessions and the login page use Craft 6's own events. The screens, routes and commands
 * still run through Craft's Yii adapter, which the spec allows for anything not yet ported.
 *
 * @property-read Connections $connections
 * @property-read Metadata $metadata
 * @property-read Rules $rules
 * @property-read Portero $portero
 */
class Plugin extends BasePlugin
{
    public const SSO_REQUIRED = 'portero_sso_required';
    public const COMPONENTS = ['connections' => Connections::class, 'rules' => Rules::class, 'portero' => Portero::class, 'metadata' => Metadata::class];

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;

    /** @var array<string, object> */
    private array $components = [];

    /** The account Craft looked up for a password sign-in. Its password event doesn't say. */
    private ?User $passwordUser = null;
    private ?string $passwordHash = null;
    /** Portero's reason for refusing a password in this request, for the reply Craft sends back. */
    private ?string $passwordRefused = null;

    public function __get(string $name): object
    {
        if (!isset(self::COMPONENTS[$name])) {
            throw new \LogicException("Unknown Portero component $name");
        }
        return $this->components[$name] ??= Craft::createObject(self::COMPONENTS[$name]);
    }

    public function boot(): void
    {
        // Craft builds its Yii app once per request, and only when something needs it.
        $this->app->afterResolving('Craft', fn($craft) => $this->attachToCraft($craft));
        if ($this->app->resolved('Craft') && Craft::$app !== null) {
            $this->attachToCraft(Craft::$app);
        }

        // Providers post to these addresses from outside. The signature and the browser binding protect them, not a CSRF token.
        PreventRequestForgery::except(['portero/saml/acs/*', 'portero/saml/slo/*', 'portero/oidc/logout/*', 'portero/oidc/backchannel/*']);
        if (!$this->app->runningInConsole() && $this->app['request']->isMethod('POST')) {
            $this->craft();
            PreventRequestForgery::except(array_keys(array_filter($this->connections->legacyRoutes(), fn($route) => $route === 'portero-sso/auth/legacy-acs')));
        }

        Events::listen(LoginUserRetrieved::class, function (LoginUserRetrieved $event) {
            $this->passwordUser = $event->user?->asElement();
            $this->passwordHash = $event->user?->getAuthPassword();
        });
        Events::listen(UserAuthenticating::class, function (UserAuthenticating $event) {
            $this->craft();
            $this->portero->restoreTwoStep();
            if ($this->passwordUser === null || !array_key_exists('password', $event->credentials)) {
                return;
            }
            if ($this->rules->refusesPassword($this->passwordUser, $event->credentials['password'], $this->passwordHash)) {
                // Craft 6 has a fixed list of sign-in errors, so the plain-words reason travels as a flash message.
                $event->authError = AuthError::InvalidCredentials;
                $event->performAuthentication = false;
                session()->flash(self::SSO_REQUIRED, $this->rules->passwordRefusedMessage());
                $this->passwordRefused = $this->rules->passwordRefusedMessage();
            }
        });
        // Craft's login page shows the message from the JSON reply, which only knows "Invalid username or password".
        // Only the reply to the refusal Portero just made gets its plain-words reason.
        Events::listen(RequestHandled::class, function (RequestHandled $event) {
            $refused = $this->passwordRefused;
            // The plugin outlives a request on long-running servers, so nothing about this sign-in may reach the next one.
            $this->passwordRefused = $this->passwordUser = $this->passwordHash = null;
            if ($refused === null || !$event->response instanceof JsonResponse) {
                return;
            }
            $data = $event->response->getData(true);
            if (($data['errorCode'] ?? null) !== AuthError::InvalidCredentials->value) {
                return;
            }
            $data['message'] = $refused;
            foreach ($data['messages'] ?? [] as $i => $message) {
                $data['messages'][$i]['message'] = $refused;
            }
            $event->response->setData($data);
        });
        Events::listen(Login::class, function (Login $event) {
            $this->craft();
            $user = $event->user instanceof User ? $event->user : $event->user->asElement();
            $guard = Auth::guard($event->guard);
            $cookieBased = method_exists($guard, 'viaRemember') && $guard->viaRemember();
            if (!$this->rules->allowsLogin($user, $cookieBased)) {
                $guard->logout();
                return;
            }
            $this->portero->afterCraftLogin($user, $cookieBased);
        });
        Events::listen(Logout::class, function () {
            $this->craft();
            $this->portero->noteLogout();
            $this->portero->forgetSession();
            $this->portero->signOutOfProvider();
        });

        $this->app->scoped(OAuth::class, LoginButtons::class);
        // The same buttons on Craft's template login page, used when the control panel's Vue page isn't served.
        $this->app->make(TemplateHooks::class)->register('cp.login.alternative-login-methods', function () {
            $this->craft();
            $refused = session(self::SSO_REQUIRED);
            return ($refused ? '<p class="error" role="alert">' . htmlspecialchars($refused, ENT_QUOTES) . '</p>' : '') . $this->portero->loginButtons();
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function (RegisterUrlRulesEvent $event) {
            $event->rules += self::publicRoutes() + $this->connections->legacyRoutes();
        });
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function (RegisterUrlRulesEvent $event) {
            $event->rules += [
                'portero-sso' => 'portero-sso/connections/index',
                'portero-sso/connections/new' => 'portero-sso/connections/step',
                'portero-sso/connections/<uid:[0-9a-f-]{36}>/step/<step:[1-5]>' => 'portero-sso/connections/step',
                'portero-sso/connections/<uid:[0-9a-f-]{36}>/summary' => 'portero-sso/connections/summary',
                'portero-sso/rules' => 'portero-sso/rules/index',
                'portero-sso/history' => 'portero-sso/history/index',
            ];
        });
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function (Event $event) {
            $event->sender->set('portero', TwigVariable::class);
        });
        Event::on(User::class, Element::EVENT_DEFINE_SIDEBAR_HTML, function (DefineHtmlEvent $event) {
            $event->html .= $this->portero->userSidebar($event->sender);
        });
        Event::on(Gc::class, Gc::EVENT_RUN, function () {
            $this->portero->collectGarbage();
            $this->metadata->schedule();
        });
        Event::on(Cp::class, Cp::EVENT_REGISTER_ALERTS, function (RegisterCpAlertsEvent $event) {
            array_push($event->alerts, ...$this->portero->cpAlerts());
        });
    }

    /** Makes sure Craft's Yii app exists, as the Craft 5 code reads Craft::$app. */
    private function craft(): void
    {
        if (Craft::$app === null) {
            $this->app->make('Craft');
        }
    }

    private function attachToCraft(object $craft): void
    {
        if ($craft->getRequest()->getIsConsoleRequest()) {
            $craft->setModule('portero', ['class' => ConsoleModule::class]);
            $craft->setModule($this->handle, ['class' => WebModule::class]);
            return;
        }
        $craft->setModule($this->handle, ['class' => WebModule::class]);
        $this->hideSecretsFromLogs($craft);
        $craft->onInit(function () {
            $this->portero->onWebRequest();
        });
    }

    /** Craft adds the request to its logs when something fails. Provider replies hold personal data, and these values open sessions. Its log target reads logVars, not maskVars. */
    private function hideSecretsFromLogs(object $craft): void
    {
        foreach ($craft->getLog()->targets as $target) {
            array_push(
                $target->logVars,
                '!_POST.SAMLResponse',
                '!_COOKIE.' . Portero::BIND_COOKIE,
                '!_COOKIE.__Host-' . Portero::BIND_COOKIE,
                '!_GET.code',
                '!_GET.state',
                '!_GET.SAMLResponse',
                '!_GET.SAMLRequest',
                '!_GET.Signature',
                '!_POST.logout_token',
                '!_SERVER.HTTP_COOKIE',
                '!_SERVER.QUERY_STRING',
                '!_SERVER.REQUEST_URI',
            );
        }
    }

    public function getCpNavItem(): array|null|\CraftCms\Cms\Cp\Data\NavItem
    {
        $this->craft();
        if (!Craft::$app->getUser()->getIsAdmin()) {
            return null;
        }
        return [
            'label' => $this->name,
            'url' => $this->handle,
            'icon' => $this->getBasePath() . '/icon-mask.svg',
            'subnav' => [
                'connections' => ['label' => Craft::t('portero-sso', 'Connections'), 'url' => 'portero-sso'],
                'rules' => ['label' => Craft::t('portero-sso', 'Sign-in rules'), 'url' => 'portero-sso/rules'],
                'history' => ['label' => Craft::t('portero-sso', 'Login history'), 'url' => 'portero-sso/history'],
            ],
        ];
    }

    /** These paths are copied into providers, so they must stay as they are. */
    public static function publicRoutes(): array
    {
        return [
            'portero/login/<handle:[a-z0-9-]+>' => 'portero-sso/auth/login',
            'portero/saml/acs/<handle:[a-z0-9-]+>' => 'portero-sso/auth/acs',
            'portero/saml/metadata/<handle:[a-z0-9-]+>' => 'portero-sso/auth/metadata',
            'portero/saml/slo/<handle:[a-z0-9-]+>' => 'portero-sso/auth/slo',
            'portero/oidc/callback/<handle:[a-z0-9-]+>' => 'portero-sso/auth/callback',
            'portero/oidc/logout/<handle:[a-z0-9-]+>' => 'portero-sso/auth/after-logout',
            'portero/oidc/backchannel/<handle:[a-z0-9-]+>' => 'portero-sso/auth/backchannel-logout',
            'portero/confirmed/<handle:[a-z0-9-]+>' => 'portero-sso/auth/confirmed',
        ];
    }
}
