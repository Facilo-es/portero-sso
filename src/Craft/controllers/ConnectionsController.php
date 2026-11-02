<?php

namespace facilo\portero\Craft\controllers;

use Craft;
use craft\enums\CmsEdition;
use craft\helpers\App;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use facilo\portero\Core\Connection;
use facilo\portero\Core\ConnectionRules;
use facilo\portero\Core\SignIn;
use facilo\portero\Core\UrlGuard;
use facilo\portero\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ConnectionsController extends Controller
{
    private const STEPS = ['Choose your provider', 'Tell your provider about this site', 'Tell this site about your provider', 'Who gets in', 'Test it'];

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requireAdmin(false);
        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $rows = [];
        foreach ($plugin->connections->all() as $uid => $raw) {
            $connection = $plugin->connections->get($uid);
            $days = $plugin->connections->certificateDays($connection);
            $rows[] = [
                'connection' => $connection,
                'lastTest' => $plugin->connections->lastTestPassed($uid),
                'days' => $days,
                'people' => $plugin->connections->people($uid),
            ];
        }
        $rules = $plugin->rules->all();
        return $this->renderTemplate('portero-sso/index', [
            'rows' => $rows,
            'history' => $plugin->portero->explain($plugin->portero->history(6)),
            'rules' => $rules,
            'breakGlass' => $this->users($rules['breakGlassUserUids'] ?? []),
        ]);
    }

    public function actionStep(?string $uid = null, int $step = 1): Response
    {
        $plugin = Plugin::getInstance();
        $raw = $uid ? $plugin->connections->raw($uid) : null;
        if ($uid && $raw === null) {
            throw new NotFoundHttpException();
        }
        $connection = $uid ? $plugin->connections->get($uid) : null;
        $variables = [
            'uid' => $uid,
            'step' => $step,
            'steps' => self::STEPS,
            'raw' => $raw ?? [],
            'connection' => $connection,
            'provider' => $connection?->providerLabel() ?? '',
            'siteOptions' => $this->siteOptions(),
            'presets' => Connection::PRESETS,
            'errors' => $this->routeErrors(),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
            'require2fa' => !empty(Craft::$app->getProjectConfig()->get('users.require2fa')),
            'hasGroups' => Craft::$app->edition->value >= CmsEdition::Pro->value,
            'solo' => Craft::$app->edition === CmsEdition::Solo,
        ];
        if ($connection) {
            $variables['urls'] = $plugin->portero->urls($connection);
            $variables['baseUrl'] = $plugin->portero->baseUrl($connection);
            $variables['lastTest'] = $plugin->connections->lastTestPassed($uid);
            $variables['people'] = $plugin->connections->people($uid);
            $variables['groupOptions'] = $this->groupOptions();
            $variables['fieldOptions'] = $this->userFieldOptions();
        }
        return $this->renderTemplate('portero-sso/connections/step', $variables);
    }

    public function actionSaveStep(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();
        $plugin = Plugin::getInstance();
        $request = $this->request;
        $step = (int) $request->getRequiredBodyParam('step');
        $uid = $request->getBodyParam('uid') ?: null;
        $raw = $uid ? ($plugin->connections->raw($uid) ?? throw new NotFoundHttpException()) : null;

        try {
            $raw = match ($step) {
                1 => $this->stepOne($raw),
                3 => $this->stepThree($raw),
                4 => $this->stepFour($raw),
                default => $raw,
            };
        } catch (\InvalidArgumentException $e) {
            $this->setFailFlash($e->getMessage());
            return null;
        }

        $errors = ConnectionRules::check($raw);
        if ($errors !== []) {
            $this->setFailFlash(Craft::t('portero-sso', reset($errors)));
            Craft::$app->getUrlManager()->setRouteParams(['errors' => array_map(static fn($e) => Craft::t('portero-sso', $e), $errors)]);
            return null;
        }
        $uid ??= $plugin->connections->newUid();
        $plugin->connections->save($uid, $raw);
        return $this->redirect(UrlHelper::cpUrl("portero-sso/connections/$uid/step/" . min(5, $step + 1)));
    }

    public function actionTest(): Response
    {
        $this->requirePostRequest();
        $uid = (string) $this->request->getRequiredBodyParam('uid');
        $plugin = Plugin::getInstance();
        $connection = $plugin->connections->get($uid) ?? throw new NotFoundHttpException();
        if (!$plugin->portero->onItsAddress($connection)) {
            $this->setFailFlash(Craft::t('portero-sso', 'Open the control panel on {url} to run this test.', ['url' => $plugin->portero->baseUrl($connection)]));
            return $this->redirect(UrlHelper::cpUrl("portero-sso/connections/$uid/step/5"));
        }
        try {
            return $this->redirect($plugin->portero->start($connection, SignIn::KIND_TEST, null));
        } catch (\facilo\portero\Core\Refused $refused) {
            $this->setFailFlash(Craft::t('portero-sso', \facilo\portero\Core\Reason::explain($refused->reason), ['provider' => $connection->providerLabel(), 'detail' => $refused->detail, 'status' => '']));
            return $this->redirect(UrlHelper::cpUrl("portero-sso/connections/$uid/step/5"));
        }
    }

    public function actionFinish(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();
        $uid = (string) $this->request->getRequiredBodyParam('uid');
        $connections = Plugin::getInstance()->connections;
        $raw = $connections->raw($uid) ?? throw new NotFoundHttpException();
        $raw['enabled'] = true;
        $connections->save($uid, $raw);
        return $this->redirect(UrlHelper::cpUrl("portero-sso/connections/$uid/summary"));
    }

    public function actionToggle(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();
        $uid = (string) $this->request->getRequiredBodyParam('uid');
        $connections = Plugin::getInstance()->connections;
        $raw = $connections->raw($uid) ?? throw new NotFoundHttpException();
        if (!empty($raw['enabled']) && $this->refuseIfStranding($uid)) {
            return $this->redirect(UrlHelper::cpUrl('portero-sso'));
        }
        $raw['enabled'] = empty($raw['enabled']);
        $connections->save($uid, $raw);
        return $this->redirect(UrlHelper::cpUrl('portero-sso'));
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();
        $uid = (string) $this->request->getRequiredBodyParam('uid');
        if (!$this->refuseIfStranding($uid)) {
            Plugin::getInstance()->connections->delete($uid);
        }
        return $this->redirect(UrlHelper::cpUrl('portero-sso'));
    }

    private function refuseIfStranding(string $uid): bool
    {
        if (!Plugin::getInstance()->rules->wouldStrand($uid)) {
            return false;
        }
        $this->setFailFlash(Craft::t('portero-sso', 'Single sign-on is required, and this is the last tested connection. Change the sign-in rules first.'));
        return true;
    }

    public function actionSummary(string $uid): Response
    {
        $plugin = Plugin::getInstance();
        $connection = $plugin->connections->get($uid) ?? throw new NotFoundHttpException();
        $rules = $plugin->rules->all();
        return $this->renderTemplate('portero-sso/connections/summary', [
            'connection' => $connection,
            'provider' => $connection->providerLabel(),
            'days' => $plugin->connections->certificateDays($connection),
            'lastTest' => $plugin->connections->lastTestPassed($uid),
            'rules' => $rules,
            'breakGlass' => $this->users($rules['breakGlassUserUids'] ?? []),
        ]);
    }

    private function stepOne(?array $raw): array
    {
        $request = $this->request;
        $preset = (string) $request->getBodyParam('preset');
        if (!isset(Connection::PRESETS[$preset])) {
            throw new BadRequestHttpException('Unknown provider');
        }
        $protocols = Connection::PRESETS[$preset]['protocols'];
        $protocol = in_array($request->getBodyParam('protocol'), $protocols, true) ? $request->getBodyParam('protocol') : $protocols[0];
        $label = Connection::PRESETS[$preset]['label'];
        $name = trim((string) $request->getBodyParam('name'));
        $values = [
            'name' => $name,
            'preset' => $preset,
            'protocol' => $protocol,
            'buttonLabel' => trim((string) $request->getBodyParam('buttonLabel')),
            'showOnCpLogin' => (bool) ($request->getBodyParam('showOnCpLogin') ?? true),
        ];
        $known = array_column($this->siteOptions(), 'value');
        if (count($known) > 1) {
            $sites = $request->getBodyParam('sites');
            $values['sites'] = is_array($sites) ? array_values(array_intersect($sites, $known)) : [];
            // All sites posts a star, and no ticks at all post nothing. Empty means all, so that second one can't be saved.
            if ($values['sites'] === [] && $sites !== '*') {
                throw new \InvalidArgumentException(Craft::t('portero-sso', 'Choose at least one site.'));
            }
            $address = (string) $request->getBodyParam('addressSite');
            $values['addressSite'] = in_array($address, $known, true) ? $address : '';
        }
        if ($raw !== null) {
            $changed = $raw['preset'] !== $preset || $raw['protocol'] !== $protocol;
            return array_merge($raw, $values, $changed ? ['attributeMap' => Connection::defaultAttributes($preset, $protocol)] : []);
        }
        $handle = $this->uniqueHandle($preset === 'saml' || $preset === 'oidc' ? ($name ?: $preset) : $preset);
        $portero = Plugin::getInstance()->portero;
        $base = $portero->siteBase($values['addressSite'] ?? '') ?? $portero->baseUrl();
        return $values + [
            'handle' => $handle,
            'enabled' => false,
            'allowedDomains' => [],
            'createAccounts' => false,
            'matchByEmail' => false,
            'matchAdmins' => false,
            'updateOnSignIn' => true,
            'defaultGroups' => [],
            'groupRules' => [],
            'requireGroupMatch' => false,
            'adminRule' => '',
            'trustProvider2fa' => false,
            'maxSessionHours' => null,
            'signOutOfProvider' => false,
            'acceptProviderLogout' => false,
            'groupsByHandle' => false,
            'manageAllGroups' => false,
            'attributeMap' => Connection::defaultAttributes($preset, $protocol),
            'saml' => $protocol === Connection::SAML ? ['spEntityId' => "$base/portero/saml/$handle", 'certs' => []] : [],
            'oidc' => [],
        ] + ['name' => $label];
    }

    private function stepThree(array $raw): array
    {
        $request = $this->request;
        if ($raw['protocol'] === Connection::OIDC) {
            $issuer = (string) App::parseEnv(trim((string) $request->getBodyParam('issuer')));
            if ($issuer !== '' && !Craft::$app->getConfig()->getGeneral()->devMode && !UrlGuard::allowed($issuer)) {
                throw new \InvalidArgumentException(Craft::t('portero-sso', 'The issuer address must be public HTTPS.'));
            }
            $raw['oidc'] = array_merge($raw['oidc'] ?? [], [
                'issuer' => trim((string) $request->getBodyParam('issuer')),
                'clientId' => trim((string) $request->getBodyParam('clientId')),
                'clientSecret' => trim((string) $request->getBodyParam('clientSecret')),
                'scope' => trim((string) $request->getBodyParam('scope')) ?: 'openid email profile',
            ]);
            return $raw;
        }
        $saml = array_merge($raw['saml'] ?? [], [
            'nameIdFormat' => (string) $request->getBodyParam('nameIdFormat'),
            'stableAttribute' => trim((string) $request->getBodyParam('stableAttribute')),
            'signRequests' => (bool) $request->getBodyParam('signRequests'),
            'encryptAssertions' => (bool) $request->getBodyParam('encryptAssertions'),
            'spPrivateKey' => trim((string) $request->getBodyParam('spPrivateKey')),
            'spCertificate' => trim((string) $request->getBodyParam('spCertificate')),
        ]);
        $source = (string) $request->getBodyParam('source', 'url');
        $portero = Plugin::getInstance()->portero;
        if ($source === 'typed') {
            $saml = array_merge($saml, [
                'idpEntityId' => trim((string) $request->getBodyParam('idpEntityId')),
                'ssoUrl' => trim((string) $request->getBodyParam('ssoUrl')),
                'sloUrl' => trim((string) $request->getBodyParam('sloUrl')),
                'metadataUrl' => '',
                'certs' => array_values(array_filter(array_map(static fn($c) => trim($c), preg_split('/-----END CERTIFICATE-----/', (string) $request->getBodyParam('certs'))))),
            ]);
        } else {
            $xml = (string) $request->getBodyParam('metadataXml');
            $saml['metadataUrl'] = '';
            if ($source === 'xml' && trim($xml) === '' && !empty($saml['ssoUrl'])) {
                $saml['source'] = $source;
                $raw['saml'] = $saml;
                return $raw;
            }
            if ($source === 'url') {
                $url = trim((string) $request->getBodyParam('metadataUrl'));
                $resolved = (string) App::parseEnv($url);
                if (!Craft::$app->getConfig()->getGeneral()->devMode && !UrlGuard::allowed($resolved)) {
                    throw new \InvalidArgumentException(Craft::t('portero-sso', 'The metadata address must be public HTTPS.'));
                }
                try {
                    $reply = (new \facilo\portero\Craft\CraftHttp())->get($resolved);
                } catch (\Throwable) {
                    throw new \InvalidArgumentException(Craft::t('portero-sso', "That address couldn't be reached."));
                }
                if ($reply['status'] !== 200) {
                    throw new \InvalidArgumentException(Craft::t('portero-sso', "That address couldn't be reached."));
                }
                $xml = $reply['body'];
                $saml['metadataUrl'] = $url;
            }
            try {
                $saml = array_merge($saml, $portero->saml()->parseIdpMetadata($xml));
            } catch (\Throwable) {
                throw new \InvalidArgumentException(Craft::t('portero-sso', 'No identity provider found in this metadata.'));
            }
        }
        $saml['source'] = $source;
        $raw['saml'] = $saml;
        Plugin::getInstance()->connections->clearMetadata((string) $request->getBodyParam('uid'));
        return $raw;
    }

    private function stepFour(array $raw): array
    {
        $request = $this->request;
        $domains = preg_split('/[\s,]+/', mb_strtolower((string) $request->getBodyParam('allowedDomains')), -1, PREG_SPLIT_NO_EMPTY);
        $rules = [];
        foreach ((array) $request->getBodyParam('groupRules') as $row) {
            if (trim((string) ($row['value'] ?? '')) !== '' && ($row['group'] ?? '') !== '') {
                $rules[] = ['value' => trim($row['value']), 'group' => $row['group']];
            }
        }
        return array_merge($raw, [
            'allowedDomains' => $domains,
            'createAccounts' => (bool) $request->getBodyParam('createAccounts'),
            'matchByEmail' => (bool) $request->getBodyParam('matchByEmail'),
            'matchAdmins' => (bool) $request->getBodyParam('matchAdmins'),
            'updateOnSignIn' => (bool) $request->getBodyParam('updateOnSignIn'),
            'groupRules' => $rules,
            'defaultGroups' => array_values(array_intersect((array) $request->getBodyParam('defaultGroups'), array_column($this->groupOptions(), 'value'))),
            'attributeMap' => $this->attributeMap($raw),
            'signOutOfProvider' => (bool) $request->getBodyParam('signOutOfProvider'),
            'acceptProviderLogout' => (bool) $request->getBodyParam('acceptProviderLogout'),
            'groupsByHandle' => (bool) $request->getBodyParam('groupsByHandle'),
            'manageAllGroups' => (bool) $request->getBodyParam('manageAllGroups'),
            'requireGroupMatch' => (bool) $request->getBodyParam('requireGroupMatch'),
            'adminRule' => trim((string) $request->getBodyParam('adminRule')),
            'trustProvider2fa' => (bool) $request->getBodyParam('trustProvider2fa'),
            'maxSessionHours' => ((int) $request->getBodyParam('maxSessionHours')) ?: null,
            'saml' => ($raw['saml'] ?? []) ? array_merge($raw['saml'], ['allowIdpInitiated' => (bool) $request->getBodyParam('allowIdpInitiated')]) : [],
        ]);
    }

    private function attributeMap(array $raw): array
    {
        $map = Connection::defaultAttributes($raw['preset'], $raw['protocol']);
        foreach (array_keys($map) as $key) {
            $value = trim((string) $this->request->getBodyParam("attribute.$key"));
            $map[$key] = $value !== '' ? $value : $map[$key];
        }
        $known = array_column($this->userFieldOptions(), 'value');
        foreach ((array) $this->request->getBodyParam('customAttributes') as $row) {
            $handle = (string) ($row['field'] ?? '');
            $name = trim((string) ($row['attribute'] ?? ''));
            if ($name !== '' && in_array($handle, $known, true)) {
                $map['custom'][$handle] = $name;
            }
        }
        return $map;
    }

    /** Plain text fields on the user layout, since a provider only ever sends text. */
    private function userFieldOptions(): array
    {
        $options = [];
        foreach (Craft::$app->getFields()->getLayoutByType(\craft\elements\User::class)->getCustomFields() as $field) {
            if ($field instanceof \craft\fields\PlainText) {
                $options[] = ['label' => $field->name, 'value' => $field->handle];
            }
        }
        return $options;
    }

    private function uniqueHandle(string $from): string
    {
        $base = trim(StringHelper::toKebabCase($from), '-') ?: 'connection';
        $base = preg_replace('/^[^a-z]+/', '', $base) ?: 'connection';
        $taken = array_column(Plugin::getInstance()->connections->all(), 'handle');
        $handle = $base;
        for ($i = 2; in_array($handle, $taken, true); $i++) {
            $handle = "$base-$i";
        }
        return $handle;
    }

    /** @return array<int,array{label:string, value:string}> sites by UID, since project config moves between environments */
    private function siteOptions(): array
    {
        return array_map(static fn(\craft\models\Site $site) => ['label' => $site->getName(), 'value' => $site->uid], Craft::$app->getSites()->getAllSites(true));
    }

    /** A link can be wrong, and a matched account can belong to someone else, so an admin must be able to undo it. The account itself stays. */
    public function actionUnlink(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin(false);
        Craft::$app->getDb()->createCommand()->delete('{{%portero_identities}}', ['id' => (int) $this->request->getRequiredBodyParam('linkId')])->execute();
        $this->setSuccessFlash(Craft::t('portero-sso', 'Link removed.'));
        return $this->redirectToPostedUrl();
    }

    private function groupOptions(): array
    {
        $options = [];
        foreach (Craft::$app->getUserGroups()->getAllGroups() as $group) {
            $options[] = ['label' => $group->name, 'value' => $group->handle];
        }
        return $options;
    }

    private function routeErrors(): array
    {
        return Craft::$app->getUrlManager()->getRouteParams()['errors'] ?? [];
    }

    private function users(array $uids): array
    {
        return $uids === [] ? [] : \craft\elements\User::find()->uid($uids)->status(null)->addSelect(['users.password'])->all();
    }
}
