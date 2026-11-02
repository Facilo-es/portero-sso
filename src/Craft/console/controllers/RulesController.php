<?php

namespace facilo\portero\Craft\console\controllers;

use craft\console\Controller;
use facilo\portero\Plugin;
use yii\console\ExitCode;
use yii\helpers\Console;

class RulesController extends Controller
{
    /** Lets everyone use a password again. Works when every provider is down. */
    public function actionOff(): int
    {
        Plugin::getInstance()->rules->turnOff();
        $this->stdout("Portero sign-in rules are off. Passwords work for everyone.\n", Console::FG_GREEN);
        $this->stdout("If config/project is in your repository, make the same change there, or the next deploy turns the rules back on.\n");
        return ExitCode::OK;
    }
}
