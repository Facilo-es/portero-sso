<?php

namespace facilo\portero\Craft\console\controllers;

use craft\console\Controller;
use facilo\portero\Plugin;
use yii\console\ExitCode;
use yii\helpers\Console;

class MetadataController extends Controller
{
    /** Refreshes every connection that has a metadata URL. A failed fetch keeps the last good copy. */
    public function actionRefresh(): int
    {
        $results = Plugin::getInstance()->metadata->refreshAll();
        if ($results === []) {
            $this->stdout("No connection has a metadata URL.\n");
        }
        foreach ($results as $handle => $refreshed) {
            $refreshed ? $this->stdout("$handle: updated\n", Console::FG_GREEN) : $this->stdout("$handle: not updated, keeping the last good copy\n", Console::FG_RED);
        }
        return in_array(false, $results, true) ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
