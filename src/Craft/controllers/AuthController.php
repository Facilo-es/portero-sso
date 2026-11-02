<?php

namespace facilo\portero\Craft\controllers;

use Craft;
use craft\web\Controller;
use craft\web\View;
use facilo\portero\Core\Connection;
use facilo\portero\Core\Result;
use facilo\portero\Core\SignIn;
use facilo\portero\Craft\assets\PorteroAsset;
use facilo\portero\Craft\CraftSession;
use facilo\portero\Craft\TestReport;
use facilo\portero\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/** The public sign-in addresses. */
class AuthController extends Controller
{
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE | self::ALLOW_ANONYMOUS_OFFLINE;

    public function beforeAction($action): bool
    {
        // Providers post to this address from outside. The signature and the browser binding protect it, not a CSRF token.
        if (in_array($action->id, ['acs', 'legacy-acs', 'slo', 'after-logout', 'backchannel-logout'], true)) {
            $this->enableCsrfValidation = false;
        }
        return parent::beforeAction($action);
    }

    public function actionLogin(string $handle): Response
    {
        return $this->startLogin($this->connection($handle));
    }

    /** The old plugin's sign-in link. */
    public function actionLegacyLogin(): Response
    {
        return $this->startLogin(Plugin::getInstance()->connections->byLegacyLogin($this->request->getPathInfo()) ?? throw new NotFoundHttpException());
    }

    private function startLogin(Connection $connection): Response
    {
        $portero = Plugin::getInstance()->portero;
        try {
            $return = $this->request->getQueryParam('return');
            $return = is_string($return) ? $return : null;
            if (!$portero->onItsAddress($connection) && !$this->request->getQueryParam('moved')) {
                return $this->redirect($portero->baseUrl($connection) . '/portero/login/' . $connection->handle . '?' . http_build_query(array_filter(['return' => $return, 'moved' => 1])));
            }
            if (!$portero->allowedFor($connection, $return)) {
                throw new \facilo\portero\Core\Refused('wrong_site');
            }
            return $this->redirect($portero->start($connection, SignIn::KIND_LOGIN, $return));
        } catch (\facilo\portero\Core\Refused $refused) {
            return $this->failedPage($refused->reason);
        }
    }

    public function actionAcs(string $handle): Response
    {
        $this->requirePostRequest();
        return $this->consumeSaml($this->connection($handle));
    }

    /** The old plugin's sign-in address. */
    public function actionLegacyAcs(): Response
    {
        $this->requirePostRequest();
        $response = $this->samlResponse();
        $connections = Plugin::getInstance()->connections;
        $connection = $connections->byLegacyAcs($this->request->getPathInfo(), Plugin::getInstance()->portero->saml()->issuer($response));
        return $this->consumeSaml($connection ?? throw new NotFoundHttpException());
    }

    private function consumeSaml(Connection $connection): Response
    {
        $portero = Plugin::getInstance()->portero;
        $result = $portero->signIn()->finishSaml(
            $connection,
            $portero->urls($connection),
            $this->samlResponse(),
            $portero->binding(),
            Plugin::getInstance()->connections->spKeys($connection),
            $portero->allowedHere($connection),
        );
        return $this->finish($connection, $result);
    }

    private function samlResponse(): string
    {
        $value = $this->request->getBodyParam('SAMLResponse');
        return is_string($value) ? $value : '';
    }

    /** The provider's answer to a sign-out from here, or its own request to end a session. */
    public function actionSlo(string $handle): Response
    {
        if ($this->request->getQueryParam('SAMLRequest') === null) {
            return $this->actionAfterLogout($handle);
        }
        $connection = $this->connection($handle);
        $portero = Plugin::getInstance()->portero;
        try {
            return $this->redirect($portero->providerLogout()->saml($connection, $portero->urls($connection), Plugin::getInstance()->connections->spKeys($connection)));
        } catch (\facilo\portero\Core\Refused $refused) {
            return $this->failedPage($refused->reason);
        }
    }

    /** OpenID Connect Back-Channel Logout. The provider's server calls this directly, so it answers with a status and nothing else. */
    public function actionBackchannelLogout(string $handle): Response
    {
        $this->requirePostRequest();
        $connection = $this->connection($handle);
        $token = $this->request->getBodyParam('logout_token');
        $this->response->format = Response::FORMAT_RAW;
        $this->response->getHeaders()->set('Cache-Control', 'no-store');
        try {
            Plugin::getInstance()->portero->providerLogout()->oidc($connection, is_string($token) ? $token : '');
        } catch (\facilo\portero\Core\Refused $refused) {
            // There's no person to show this to, and Login history keeps only one refusal a minute. The detail names a check, never a value.
            Craft::warning("Portero: back-channel logout refused: {$refused->reason} {$refused->detail}", __METHOD__);
            $this->response->setStatusCode(400);
        }
        return $this->response;
    }

    /** Whatever arrives here only sends the person on. It changes nothing. */
    public function actionAfterLogout(string $handle): Response
    {
        return $this->redirect(Plugin::getInstance()->portero->afterLogoutUrl());
    }

    /** F11: opens the provider in a popup, for people with no password to type into "Confirm your identity". */
    public function actionConfirm(): Response
    {
        $this->requireLogin();
        $state = Craft::$app->getSession()->get(CraftSession::SESSION_KEY);
        $connection = is_array($state) ? Plugin::getInstance()->connections->get($state['connection']) : null;
        if ($connection === null) {
            throw new NotFoundHttpException();
        }
        try {
            return $this->redirect(Plugin::getInstance()->portero->start($connection, SignIn::KIND_REAUTH, null));
        } catch (\facilo\portero\Core\Refused $refused) {
            return $this->failedPage($refused->reason);
        }
    }

    public function actionConfirmed(string $handle): Response
    {
        $this->requireLogin();
        $this->connection($handle);
        if (!Plugin::getInstance()->portero->elevateFromConfirmation(Craft::$app->getUser()->getIdentity())) {
            return $this->failedPage('no_account');
        }
        return $this->renderPublic('portero-sso/_public/confirmed', []);
    }

    public function actionCallback(string $handle): Response
    {
        $connection = $this->connection($handle);
        $portero = Plugin::getInstance()->portero;
        $result = $portero->signIn()->finishOidc(
            $connection,
            $portero->urls($connection),
            $this->request->getQueryParams(),
            $portero->binding(),
            $portero->secret($connection),
        );
        return $this->finish($connection, $result);
    }

    public function actionMetadata(string $handle): Response
    {
        $connection = $this->connection($handle);
        if ($connection->protocol !== Connection::SAML) {
            throw new NotFoundHttpException();
        }
        $portero = Plugin::getInstance()->portero;
        $this->response->format = Response::FORMAT_RAW;
        $this->response->getHeaders()->set('Content-Type', 'application/samlmetadata+xml');
        $this->response->content = $portero->saml()->spMetadata($connection, $portero->urls($connection), Plugin::getInstance()->connections->spKeys($connection));
        return $this->response;
    }

    private function connection(string $handle): Connection
    {
        return Plugin::getInstance()->connections->byHandle($handle) ?? throw new NotFoundHttpException();
    }

    private function finish(Connection $connection, Result $result): Response
    {
        if ($result->kind === SignIn::KIND_TEST) {
            if ($result->passed()) {
                Plugin::getInstance()->connections->markTestPassed($connection->uid);
            }
            // Shown here and now, and never stored, because it can hold personal data.
            return $this->renderPublic('portero-sso/_public/test-result', [
                'connection' => $connection,
                'report' => (new TestReport())->build($connection, $result),
                'nextUrl' => \craft\helpers\UrlHelper::cpUrl("portero-sso/connections/{$connection->uid}/step/5"),
            ]);
        }
        if (!$result->passed()) {
            return $this->failedPage($result->refused->reason);
        }
        if ($result->kind === SignIn::KIND_REAUTH) {
            $portero = Plugin::getInstance()->portero;
            $portero->rememberConfirmation($result->decision->user->id);
            return $this->redirect($portero->baseUrl($connection) . '/portero/confirmed/' . $connection->handle);
        }
        return $this->redirect(Plugin::getInstance()->portero->afterLoginUrl($connection, $result));
    }

    private function failedPage(string $code): Response
    {
        $this->response->setStatusCode(403);
        return $this->renderPublic('portero-sso/_public/failed', [
            'code' => $code,
            'loginUrl' => \craft\helpers\UrlHelper::cpUrl('login'),
        ]);
    }

    private function renderPublic(string $template, array $variables): Response
    {
        $view = Craft::$app->getView();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);
        $view->registerAssetBundle(PorteroAsset::class);
        return $this->renderTemplate($template, $variables, View::TEMPLATE_MODE_CP);
    }
}
