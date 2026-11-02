<?php

namespace facilo\portero\Craft;

use Craft;
use facilo\portero\Core\Ports\HttpClient;
use facilo\portero\Core\Refused;
use facilo\portero\Core\UrlGuard;
use GuzzleHttp\Exception\GuzzleException;

final class CraftHttp implements HttpClient
{
    public function get(string $url): array
    {
        return $this->send('GET', $url, []);
    }

    public function postForm(string $url, array $fields): array
    {
        return $this->send('POST', $url, ['form_params' => $fields]);
    }

    /** A provider that can't be reached counts as a bad reply, so the sign-in ends in a plain refusal and not a 500. */
    private function send(string $method, string $url, array $options): array
    {
        if (!Craft::$app->getConfig()->getGeneral()->devMode) {
            $pin = UrlGuard::pin($url);
            if ($pin === null) {
                throw new Refused('oidc_token_invalid', 'address must be public HTTPS');
            }
            $ip = str_contains($pin['ip'], ':') ? "[{$pin['ip']}]" : $pin['ip'];
            $options['curl'] = [CURLOPT_RESOLVE => ["{$pin['host']}:{$pin['port']}:$ip"]];
        }
        try {
            $reply = Craft::createGuzzleClient(['timeout' => 10, 'connect_timeout' => 5, 'http_errors' => false, 'allow_redirects' => false])
                ->request($method, $url, $options);
        } catch (GuzzleException) {
            return ['status' => 0, 'body' => ''];
        }
        return ['status' => $reply->getStatusCode(), 'body' => (string) $reply->getBody()];
    }
}
