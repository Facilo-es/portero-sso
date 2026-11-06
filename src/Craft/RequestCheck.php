<?php

namespace facilo\portero\Craft;

use Closure;
use facilo\portero\Plugin;
use Illuminate\Http\Request;

/**
 * Portero's per-request check: the longest session, sign-outs started by the provider, trusted second steps and the
 * Confirm button. On Craft 6 the Yii app starts before the session, so this runs as middleware, once Craft knows who is signed in.
 */
class RequestCheck
{
    public function handle(Request $request, Closure $next): mixed
    {
        $plugin = Plugin::getInstance();
        if (($redirect = $plugin->checkRequest()) !== null) {
            return redirect($redirect);
        }
        $response = $next($request);
        // Craft 6 signs out first and redirects after, so the provider's sign-out takes the place of Craft's redirect.
        if (($url = $plugin->providerLogoutUrl()) !== null) {
            return redirect()->away($url);
        }
        return $response;
    }
}
