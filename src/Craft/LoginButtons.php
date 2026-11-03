<?php

namespace facilo\portero\Craft;

use Craft;
use CraftCms\Cms\Auth\OAuth\OAuth;
use facilo\portero\Plugin;
use Illuminate\Support\HtmlString;

/**
 * Craft 6's control panel login is a Vue page whose only place for other sign-in methods is the list of OAuth buttons.
 * Portero adds its buttons to that list. Craft's own OAuth providers, when a site has any, keep working as before.
 */
class LoginButtons extends OAuth
{
    public function getLoginButtons(?bool $isCpRequest = null): array
    {
        $buttons = parent::getLoginButtons($isCpRequest);
        if (Craft::$app === null) {
            app('Craft');
        }
        $plugin = Plugin::getInstance();
        $refused = session(Plugin::SSO_REQUIRED);
        $html = ($refused ? '<p class="error" role="alert">' . htmlspecialchars($refused, ENT_QUOTES) . '</p>' : '') . $plugin->portero->loginButtons();
        if ($html !== '') {
            $buttons[] = new HtmlString($html);
        }
        return $buttons;
    }
}
