<?php

namespace facilo\portero\Craft\controllers;

use craft\web\Controller;
use facilo\portero\Plugin;
use yii\web\Response;

class HistoryController extends Controller
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
        $plugin = Plugin::getInstance();
        $connection = $this->request->getQueryParam('connection') ?: null;
        $outcome = $this->request->getQueryParam('outcome') ?: null;
        return $this->renderTemplate('portero-sso/history', [
            'rows' => $plugin->portero->explain($plugin->portero->history(200, $connection, $outcome)),
            'connections' => $plugin->connections->all(),
            'connection' => $connection,
            'outcome' => $outcome,
            'days' => $plugin->rules->all()['historyDays'],
        ]);
    }
}
