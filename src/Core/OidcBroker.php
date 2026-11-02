<?php

namespace facilo\portero\Core;

use facilo\portero\Core\Ports\Clock;
use facilo\portero\Core\Ports\HttpClient;
use facilo\portero\Core\Ports\KeyCache;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

/** OpenID Connect Authorization Code with PKCE. Token signatures are checked by firebase/php-jwt. */
final class OidcBroker
{
    public const ALGORITHMS = ['RS256', 'ES256'];
    private const LEEWAY = 60;
    private const KEYS_SECONDS = 3600;
    private const REFETCH_SECONDS = 60;
    private const LOGOUT_SECONDS = 600;
    private const LOGOUT_EVENT = 'http://schemas.openid.net/event/backchannel-logout';

    public function __construct(
        private readonly HttpClient $http,
        private readonly KeyCache $cache,
        private readonly Clock $clock,
    ) {
    }

    public function discovery(string $issuer): array
    {
        $key = 'portero.oidc.discovery.' . md5($issuer);
        $doc = $this->cache->get($key);
        if (is_array($doc)) {
            return $doc;
        }
        $reply = $this->http->get(rtrim($issuer, '/') . '/.well-known/openid-configuration');
        $doc = $reply['status'] === 200 ? json_decode($reply['body'], true) : null;
        if (!is_array($doc) || empty($doc['authorization_endpoint']) || empty($doc['token_endpoint']) || empty($doc['jwks_uri'])) {
            throw new Refused('oidc_token_invalid', 'discovery document not usable');
        }
        if (rtrim((string) ($doc['issuer'] ?? ''), '/') !== rtrim($issuer, '/')) {
            throw new Refused('oidc_token_invalid', 'discovery issuer differs');
        }
        $this->cache->set($key, $doc, self::KEYS_SECONDS);
        return $doc;
    }

    public function authorizationUrl(Connection $connection, string $redirectUri, string $state, string $nonce, string $verifier, bool $forceLogin = false, array $extraParams = []): string
    {
        // Added last, so a module can pass a login hint but never replace anything that protects the sign-in.
        $query = [
            'response_type' => 'code',
            'client_id' => $connection->oidc['clientId'] ?? '',
            'redirect_uri' => $redirectUri,
            'scope' => $connection->oidc['scope'] ?? 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::challenge($verifier),
            'code_challenge_method' => 'S256',
        ];
        if ($forceLogin) {
            $query['prompt'] = 'login';
            $query['max_age'] = 0;
        }
        $query += $extraParams;
        $endpoint = $this->discovery($connection->oidc['issuer'])['authorization_endpoint'];
        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** The provider's logout address, or null if it has none. */
    public function logoutUrl(Connection $connection, string $afterLogout, ?string $idToken): ?string
    {
        $endpoint = $this->discovery($connection->oidc['issuer'])['end_session_endpoint'] ?? '';
        if ($endpoint === '') {
            return null;
        }
        $query = array_filter([
            'client_id' => $connection->oidc['clientId'] ?? '',
            'post_logout_redirect_uri' => $afterLogout,
            'id_token_hint' => $idToken,
        ]);
        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** @throws Refused */
    public function exchange(Connection $connection, string $code, string $verifier, string $redirectUri, string $secret): string
    {
        $doc = $this->discovery($connection->oidc['issuer']);
        $reply = $this->http->postForm($doc['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $connection->oidc['clientId'] ?? '',
            'client_secret' => $secret,
            'code_verifier' => $verifier,
        ]);
        $body = json_decode($reply['body'], true);
        if ($reply['status'] !== 200 || !is_array($body) || empty($body['id_token'])) {
            throw new Refused('oidc_exchange_failed', (string) ($body['error'] ?? "status {$reply['status']}"));
        }
        return $body['id_token'];
    }

    /** @throws Refused */
    public function validate(Connection $connection, string $idToken, string $nonce, ?int $freshSince = null): Identity
    {
        $issuer = (string) ($connection->oidc['issuer'] ?? '');
        $clientId = (string) ($connection->oidc['clientId'] ?? '');

        $parts = explode('.', $idToken);
        $header = count($parts) === 3 ? json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true) : null;
        if (!is_array($header) || !in_array($header['alg'] ?? '', self::ALGORITHMS, true)) {
            throw new Refused('oidc_token_invalid', 'algorithm not allowed');
        }

        $claims = $this->verified($connection, $idToken, is_string($header['kid'] ?? null) ? $header['kid'] : '', $header['alg']);

        if (rtrim((string) ($claims['iss'] ?? ''), '/') !== rtrim($issuer, '/')) {
            throw new Refused('oidc_token_invalid', 'iss');
        }
        $audience = (array) ($claims['aud'] ?? []);
        if (!in_array($clientId, $audience, true)) {
            throw new Refused('oidc_token_invalid', 'aud');
        }
        if ((count($audience) > 1 && ($claims['azp'] ?? null) === null) || (isset($claims['azp']) && $claims['azp'] !== $clientId)) {
            throw new Refused('oidc_token_invalid', 'azp');
        }
        if (empty($claims['iat']) || $claims['iat'] > $this->clock->now() + self::LEEWAY) {
            throw new Refused('oidc_token_invalid', 'iat');
        }
        if (empty($claims['exp'])) {
            throw new Refused('oidc_token_invalid', 'exp');
        }
        if (!hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
            throw new Refused('oidc_nonce');
        }
        if (empty($claims['sub'])) {
            throw new Refused('oidc_token_invalid', 'sub');
        }
        if ($freshSince !== null && (int) ($claims['auth_time'] ?? 0) < $freshSince) {
            throw new Refused('not_fresh');
        }

        $attributes = [];
        foreach ($claims as $name => $value) {
            $attributes[$name] = array_map(static fn($v) => is_scalar($v) ? (string) $v : json_encode($v), is_array($value) ? array_values($value) : [$value]);
        }
        foreach (['iss', 'aud', 'azp', 'exp', 'iat', 'nbf', 'nonce', 'at_hash', 'c_hash', 'sid', 'jti', 'auth_time', 'typ', 'acr', 'session_state'] as $technical) {
            unset($attributes[$technical]);
        }

        return Identity::fromAttributes($claims['iss'] . '|' . $claims['sub'], $attributes, $connection, [
            'emailVerified' => array_key_exists('email_verified', $claims) ? filter_var($claims['email_verified'], FILTER_VALIDATE_BOOLEAN) : null,
            'groupsOverage' => isset($claims['_claim_names']['groups']) || !empty($claims['hasgroups']),
            'hostedDomain' => $claims['hd'] ?? null,
            'providerSession' => $connection->signOutOfProvider ? ['idToken' => $idToken] : [],
            'logoutName' => $claims['iss'] . '|' . $claims['sub'],
            'sessionRef' => isset($claims['sid']) ? (string) $claims['sid'] : null,
        ]);
    }

    /**
     * OpenID Connect Back-Channel Logout: the provider's server calls ours, with no browser and no cookies, so the signed token is all that vouches for it.
     *
     * @return array{name:?string, sid:?string, jti:string, expiresAt:int}
     * @throws Refused
     */
    public function logoutToken(Connection $connection, string $token): array
    {
        $parts = explode('.', $token);
        $header = count($parts) === 3 ? json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true) : null;
        if (!is_array($header) || !in_array($header['alg'] ?? '', self::ALGORITHMS, true)) {
            throw new Refused('logout_invalid', 'algorithm not allowed');
        }
        if (isset($header['typ']) && (!is_string($header['typ']) || strcasecmp($header['typ'], 'logout+jwt') !== 0)) {
            throw new Refused('logout_invalid', 'typ');
        }
        $claims = $this->verified($connection, $token, is_string($header['kid'] ?? null) ? $header['kid'] : '', $header['alg']);
        $issuer = (string) ($connection->oidc['issuer'] ?? '');
        if (rtrim((string) ($claims['iss'] ?? ''), '/') !== rtrim($issuer, '/')) {
            throw new Refused('logout_invalid', 'iss');
        }
        if (!in_array((string) ($connection->oidc['clientId'] ?? ''), (array) ($claims['aud'] ?? []), true)) {
            throw new Refused('logout_invalid', 'aud');
        }
        $now = $this->clock->now();
        $issued = (int) ($claims['iat'] ?? 0);
        if ($issued === 0 || $issued > $now + self::LEEWAY || (empty($claims['exp']) && $issued < $now - self::LOGOUT_SECONDS)) {
            throw new Refused('logout_invalid', 'iat');
        }
        if (!is_array($claims['events'][self::LOGOUT_EVENT] ?? null)) {
            throw new Refused('logout_invalid', 'events');
        }
        // A login token carries a nonce and a logout token never does, so one can't pass as the other.
        if (array_key_exists('nonce', $claims)) {
            throw new Refused('logout_invalid', 'nonce');
        }
        if (empty($claims['jti']) || (empty($claims['sub']) && empty($claims['sid']))) {
            throw new Refused('logout_invalid', 'claims');
        }
        return [
            'name' => empty($claims['sub']) ? null : $claims['iss'] . '|' . $claims['sub'],
            'sid' => empty($claims['sid']) ? null : (string) $claims['sid'],
            'jti' => (string) $claims['jti'],
            'expiresAt' => max((int) ($claims['exp'] ?? 0), $issued + self::LOGOUT_SECONDS) + self::LEEWAY,
        ];
    }

    private function verified(Connection $connection, string $idToken, string $kid, string $algorithm): array
    {
        $issuer = $connection->oidc['issuer'];
        $keys = $this->keys($issuer, false, $algorithm);
        if ($kid !== '' && !isset($keys[$kid])) {
            $keys = $this->keys($issuer, true, $algorithm);
        }
        JWT::$leeway = self::LEEWAY;
        JWT::$timestamp = $this->clock->now();
        try {
            return json_decode(json_encode(JWT::decode($idToken, $keys)), true);
        } catch (\Firebase\JWT\ExpiredException) {
            throw new Refused('oidc_token_invalid', 'exp');
        } catch (\Throwable) {
            throw new Refused('oidc_token_invalid', 'signature');
        } finally {
            JWT::$timestamp = null;
        }
    }

    /** @return array<string,\Firebase\JWT\Key> */
    private function keys(string $issuer, bool $refresh, string $algorithm): array
    {
        $cacheKey = 'portero.oidc.jwks.' . md5($issuer);
        $waitKey = $cacheKey . '.refetched';
        $jwks = $this->cache->get($cacheKey);
        if ($refresh && !$this->cache->get($waitKey)) {
            $this->cache->set($waitKey, 1, self::REFETCH_SECONDS);
            $jwks = null;
        }
        if (!is_array($jwks)) {
            // The logout address is open to anyone, so a provider outage mustn't let strangers hold a PHP worker on each request.
            $failedKey = $cacheKey . '.failed';
            if ($this->cache->get($failedKey)) {
                throw new Refused('oidc_token_invalid', 'keys not available');
            }
            try {
                $reply = $this->http->get($this->discovery($issuer)['jwks_uri']);
                $jwks = $reply['status'] === 200 ? json_decode($reply['body'], true) : null;
            } catch (\Throwable) {
                $jwks = null;
            }
            if (!is_array($jwks) || empty($jwks['keys'])) {
                $this->cache->set($failedKey, 1, self::REFETCH_SECONDS);
                throw new Refused('oidc_token_invalid', 'keys not available');
            }
            $this->cache->set($cacheKey, $jwks, self::KEYS_SECONDS);
        }
        // HMAC keys can't be used here: only keys for the two allowed algorithms get through.
        $jwks['keys'] = array_values(array_filter($jwks['keys'], static fn($key) =>
            in_array($key['kty'] ?? '', ['RSA', 'EC'], true)
            && in_array($key['alg'] ?? $algorithm, self::ALGORITHMS, true)
            && ($key['use'] ?? 'sig') === 'sig'));
        return JWK::parseKeySet($jwks, $algorithm);
    }
}
