<?php

namespace facilo\portero\Craft\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class PorteroAsset extends AssetBundle
{
    public $sourcePath = __DIR__;
    public $depends = [CpAsset::class];
    public $css = ['portero.css'];
    public $js = ['portero.js'];
}
