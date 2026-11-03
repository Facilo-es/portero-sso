<?php

namespace facilo\portero\Craft;

/** On Craft 6 the plugin is no longer a Yii module, so this one carries its control panel screens and public routes through Craft's Yii adapter. */
class WebModule extends \yii\base\Module
{
    public $controllerNamespace = 'facilo\portero\Craft\controllers';
}
