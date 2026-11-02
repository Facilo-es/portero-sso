<?php

namespace facilo\portero\Craft\controllers;

use Craft;
use craft\elements\User;
use craft\web\Controller;
use facilo\portero\Craft\Rules;
use facilo\portero\Plugin;
use yii\web\Response;

class RulesController extends Controller
{
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
        $rules = Plugin::getInstance()->rules->all();
        return $this->renderTemplate('portero-sso/rules', [
            'rules' => $rules,
            'breakGlass' => ($rules['breakGlassUserUids'] ?? []) === [] ? [] : User::find()->uid($rules['breakGlassUserUids'])->status(null)->all(),
            'blocked' => Craft::$app->getUrlManager()->getRouteParams()['blocked'] ?? null,
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();
        $service = Plugin::getInstance()->rules;
        $request = $this->request;
        $ids = array_filter((array) $request->getBodyParam('breakGlass'));
        $uids = $ids === [] ? [] : array_map(static fn(User $user) => (string) $user->uid, User::find()->id($ids)->status(null)->all());
        $mode = $request->getBodyParam('mode') === Rules::EVERYONE ? Rules::EVERYONE : Rules::NOBODY;
        if ($mode === Rules::EVERYONE) {
            $missing = $service->missingForLockout($uids);
            if ($missing !== []) {
                $message = Craft::t('portero-sso', 'Before turning this on: {list}.', ['list' => implode(', ', $missing)]);
                $this->setFailFlash($message);
                Craft::$app->getUrlManager()->setRouteParams(['blocked' => $message]);
                return null;
            }
        }
        $service->save([
            'mode' => $mode,
            'breakGlassUserUids' => array_values($uids),
            'autoRedirect' => (bool) $request->getBodyParam('autoRedirect'),
            'historyDays' => max(1, (int) ($request->getBodyParam('historyDays') ?: Rules::DEFAULT_DAYS)),
        ]);
        $this->setSuccessFlash(Craft::t('portero-sso', 'Sign-in rules saved.'));
        return $this->redirectToPostedUrl();
    }
}
