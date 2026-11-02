<?php

namespace facilo\portero;

use Craft;
use craft\base\Element;
use craft\base\Plugin as BasePlugin;
use craft\controllers\UsersController;
use craft\elements\User;
use craft\events\AuthenticateUserEvent;
use craft\events\DefineHtmlEvent;
use craft\events\LoginFailureEvent;
use craft\events\RegisterCpAlertsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\Cp;
use craft\services\Gc;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use facilo\portero\Craft\Connections;
use facilo\portero\Craft\console\Module as ConsoleModule;
use facilo\portero\Craft\Metadata;
use facilo\portero\Craft\Portero;
use facilo\portero\Craft\Rules;
use facilo\portero\Craft\TwigVariable;
use yii\base\Event;
use yii\web\User as WebUser;
use yii\web\UserEvent;

/**
 * @property-read Connections $connections
 * @property-read Metadata $metadata
 * @property-read Rules $rules
 * @property-read Portero $portero
 */
class Plugin extends BasePlugin
{
    public const SSO_REQUIRED = 'portero_sso_required';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return ['components' => ['connections' => Connections::class, 'rules' => Rules::class, 'portero' => Portero::class, 'metadata' => Metadata::class]];
    }

    public function init(): void
    {
        parent::init();
        $this->controllerNamespace = 'facilo\portero\Craft\controllers';
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            Craft::$app->setModule('portero', ['class' => ConsoleModule::class]);
        }

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
        Event::on(User::class, User::EVENT_BEFORE_AUTHENTICATE, function (AuthenticateUserEvent $event) {
            $this->portero->restoreTwoStep();
            $this->rules->guardPassword($event);
        });
        Event::on(WebUser::class, WebUser::EVENT_BEFORE_LOGIN, function (UserEvent $event) {
            $event->isValid = $event->isValid && $this->rules->allowsLogin($event->identity, $event->cookieBased);
            if ($event->cookieBased) {
                $this->portero->noteCookieToken();
            } else {
                $this->portero->noteSessionBeforeLogin();
            }
        });
        Event::on(WebUser::class, WebUser::EVENT_AFTER_LOGIN, function (UserEvent $event) {
            $this->portero->afterCraftLogin($event->identity, $event->cookieBased);
        });
        Event::on(WebUser::class, WebUser::EVENT_BEFORE_LOGOUT, function () {
            $this->portero->noteLogout();
        });
        Event::on(WebUser::class, WebUser::EVENT_AFTER_LOGOUT, function () {
            $this->portero->forgetSession();
            $this->portero->signOutOfProvider();
        });
        Event::on(UsersController::class, UsersController::EVENT_LOGIN_FAILURE, function (LoginFailureEvent $event) {
            if ($event->authError === self::SSO_REQUIRED) {
                $event->message = $this->rules->passwordRefusedMessage();
            }
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

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }
        $this->hideSecretsFromLogs();
        Craft::$app->getView()->hook('cp.login.alternative-login-methods', function () {
            return $this->portero->loginButtons();
        });
        Craft::$app->onInit(function () {
            $this->portero->onWebRequest();
        });
    }

    /** Craft adds the request to its logs when something fails. Provider replies hold personal data, and these values open sessions. Its log target reads logVars, not maskVars. */
    private function hideSecretsFromLogs(): void
    {
        foreach (Craft::$app->getLog()->targets as $target) {
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

    public function getCpNavItem(): ?array
    {
        if (!Craft::$app->getUser()->getIsAdmin()) {
            return null;
        }
        $item = parent::getCpNavItem();
        $item['subnav'] = [
            'connections' => ['label' => Craft::t('portero-sso', 'Connections'), 'url' => 'portero-sso'],
            'rules' => ['label' => Craft::t('portero-sso', 'Sign-in rules'), 'url' => 'portero-sso/rules'],
            'history' => ['label' => Craft::t('portero-sso', 'Login history'), 'url' => 'portero-sso/history'],
        ];
        return $item;
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
