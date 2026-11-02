<?php

namespace facilo\portero\tests\Core;

use facilo\portero\Core\Connection;
use facilo\portero\Core\OidcBroker;
use facilo\portero\Core\Refused;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;

final class OidcTest extends TestCase
{
    private const ISSUER = 'https://idp.test/realm';
    private const CLIENT = 'client-1';

    private static array $rsa;
    private static array $ec;
    private int $now;
    private ScriptedHttp $http;
    private MemoryCache $cache;
    private OidcBroker $broker;
    private array $published;

    public static function setUpBeforeClass(): void
    {
        self::$rsa = self::key(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::$ec = self::key(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    }

    private static function key(array $options): array
    {
        $key = openssl_pkey_new($options);
        openssl_pkey_export($key, $private);
        return ['private' => $private, 'details' => openssl_pkey_get_details($key)];
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function rsaJwk(array $key, string $kid): array
    {
        return ['kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'alg' => 'RS256', 'n' => self::b64($key['details']['rsa']['n']), 'e' => self::b64($key['details']['rsa']['e'])];
    }

    private static function ecJwk(array $key, string $kid): array
    {
        return ['kty' => 'EC', 'kid' => $kid, 'use' => 'sig', 'alg' => 'ES256', 'crv' => 'P-256', 'x' => self::b64($key['details']['ec']['x']), 'y' => self::b64($key['details']['ec']['y'])];
    }

    protected function setUp(): void
    {
        $this->now = time();
        $this->published = [self::rsaJwk(self::$rsa, 'k1'), self::ecJwk(self::$ec, 'k2')];
        $this->http = new ScriptedHttp([
            self::ISSUER . '/.well-known/openid-configuration' => json_encode(['issuer' => self::ISSUER, 'authorization_endpoint' => 'https://idp.test/auth', 'token_endpoint' => 'https://idp.test/token', 'jwks_uri' => 'https://idp.test/jwks']),
            'https://idp.test/jwks' => fn() => json_encode(['keys' => $this->published]),
        ]);
        $this->cache = new MemoryCache();
        $this->broker = new OidcBroker($this->http, $this->cache, new FixedClock($this->now));
    }

    private function claims(array $over = []): array
    {
        return $over + ['iss' => self::ISSUER, 'aud' => self::CLIENT, 'sub' => 's1', 'iat' => $this->now, 'exp' => $this->now + 300, 'nonce' => 'n1', 'email' => 'elena@acme.com', 'email_verified' => true, 'groups' => ['web-editors']];
    }

    private function connection(): Connection
    {
        return Support::connection(['protocol' => Connection::OIDC, 'oidc' => ['issuer' => self::ISSUER, 'clientId' => self::CLIENT]]);
    }

    private function rs256(array $claims, string $kid = 'k1'): string
    {
        return JWT::encode($claims, self::$rsa['private'], 'RS256', $kid);
    }

    private function refused(string $token, string $nonce = 'n1'): Refused
    {
        try {
            $this->broker->validate($this->connection(), $token, $nonce);
        } catch (Refused $refused) {
            return $refused;
        }
        $this->fail('Token was accepted');
    }

    public function testT20Rs256(): void
    {
        $identity = $this->broker->validate($this->connection(), $this->rs256($this->claims()), 'n1');
        $this->assertSame(self::ISSUER . '|s1', $identity->subject);
        $this->assertSame(['web-editors'], $identity->groups);
    }

    public function testT21Es256(): void
    {
        $token = JWT::encode($this->claims(), self::$ec['private'], 'ES256', 'k2');
        $this->assertSame('elena@acme.com', $this->broker->validate($this->connection(), $token, 'n1')->email);
    }

    public function testT22AlgNone(): void
    {
        $token = self::b64('{"alg":"none","kid":"k1"}') . '.' . self::b64(json_encode($this->claims())) . '.';
        $this->assertSame('oidc_token_invalid', $this->refused($token)->reason);
    }

    public function testT23Hs256WithPublicKey(): void
    {
        $token = self::b64('{"alg":"HS256","kid":"k1"}') . '.' . self::b64(json_encode($this->claims()));
        $token .= '.' . self::b64(hash_hmac('sha256', $token, self::$rsa['details']['key'], true));
        $this->assertSame('oidc_token_invalid', $this->refused($token)->reason);
    }

    public function testRs256TokenClaimingEsKeyRefused(): void
    {
        $this->assertSame('oidc_token_invalid', $this->refused($this->rs256($this->claims(), 'k2'))->reason);
    }

    public function testT24WrongIssuer(): void
    {
        $this->assertSame('iss', $this->refused($this->rs256($this->claims(['iss' => 'https://evil.test'])))->detail);
    }

    public function testT24WrongAudience(): void
    {
        $this->assertSame('aud', $this->refused($this->rs256($this->claims(['aud' => 'other'])))->detail);
    }

    public function testT24Expired(): void
    {
        $this->assertSame('exp', $this->refused($this->rs256($this->claims(['exp' => $this->now - 600])))->detail);
    }

    public function testAzpMustBeUs(): void
    {
        $this->assertSame('azp', $this->refused($this->rs256($this->claims(['azp' => 'other'])))->detail);
        $this->assertSame('azp', $this->refused($this->rs256($this->claims(['aud' => [self::CLIENT, 'other']])))->detail);
    }

    public function testT25WrongNonce(): void
    {
        $this->assertSame('oidc_nonce', $this->refused($this->rs256($this->claims()), 'other')->reason);
    }

    public function testT27KeyRotatedRefetchesOnce(): void
    {
        $this->broker->validate($this->connection(), $this->rs256($this->claims()), 'n1');
        $rotated = self::key(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->published = [self::rsaJwk($rotated, 'k9')];
        $token = JWT::encode($this->claims(), $rotated['private'], 'RS256', 'k9');
        $this->assertSame('elena@acme.com', $this->broker->validate($this->connection(), $token, 'n1')->email);
        $fetches = count(array_keys($this->http->gets, 'https://idp.test/jwks', true));
        $this->assertSame(2, $fetches);

        $unknown = JWT::encode($this->claims(), $rotated['private'], 'RS256', 'k-nope');
        $this->refused($unknown);
        $this->assertCount($fetches, array_keys($this->http->gets, 'https://idp.test/jwks', true));
    }

    public function testPkceAlwaysS256(): void
    {
        $url = $this->broker->authorizationUrl($this->connection(), 'https://site.test/cb', 'st', 'n1', 'verifier');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(OidcBroker::challenge('verifier'), $query['code_challenge']);
        $this->assertSame('code', $query['response_type']);
    }

    public function testEntraOverageMarkerRead(): void
    {
        $claims = $this->claims(['_claim_names' => ['groups' => 'src1']]);
        unset($claims['groups']);
        $this->assertTrue($this->broker->validate($this->connection(), $this->rs256($claims), 'n1')->groupsOverage);
    }

    public function testExchangeFailureReported(): void
    {
        $this->expectException(Refused::class);
        $this->broker->exchange($this->connection(), 'c', 'v', 'https://site.test/cb', 's');
    }

    public function testDiscoveryWithAnotherIssuerRefused(): void
    {
        $this->http->routes[self::ISSUER . '/.well-known/openid-configuration'] = json_encode(['issuer' => 'https://evil.test', 'authorization_endpoint' => 'a', 'token_endpoint' => 'b', 'jwks_uri' => 'c']);
        $this->expectException(Refused::class);
        $this->broker->discovery(self::ISSUER);
    }
}
