<?php

namespace facilo\portero\tests\Core;

use facilo\portero\Core\AccessRules;
use facilo\portero\Core\Connection;
use facilo\portero\Core\ConnectionRules;
use facilo\portero\Core\Identity;
use facilo\portero\Core\OidcBroker;
use facilo\portero\Core\ProviderLogout;
use facilo\portero\Core\Refused;
use facilo\portero\Core\SamlBroker;
use facilo\portero\Core\Urls;
use facilo\portero\Core\UserRef;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/** T61 to T74, added after Alfa's competitor review: logout started by the provider, and group options. */
final class ProviderLogoutTest extends TestCase
{
    private const ISSUER = 'https://idp.test/realm';
    private const EVENT = 'http://schemas.openid.net/event/backchannel-logout';

    private static SamlFixture $fixture;
    private static array $rsa;
    private static array $other;
    private int $now;
    private MemorySessions $sessions;
    private MemoryHistory $history;
    private ProviderLogout $logout;

    public static function setUpBeforeClass(): void
    {
        self::$fixture = new SamlFixture();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        self::$rsa = ['private' => $private, 'details' => openssl_pkey_get_details($key)];
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        self::$other = ['private' => $private];
    }

    private static function discovery(): string
    {
        return json_encode(['issuer' => self::ISSUER, 'authorization_endpoint' => 'https://idp.test/a', 'token_endpoint' => 'https://idp.test/t', 'jwks_uri' => 'https://idp.test/k']);
    }

    protected function setUp(): void
    {
        $this->now = 1_800_000_000;
        $this->sessions = new MemorySessions();
        $this->history = new MemoryHistory();
        $clock = new FixedClock($this->now);
        $details = self::$rsa['details']['rsa'];
        $b64 = static fn(string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $http = new ScriptedHttp([
            self::ISSUER . '/.well-known/openid-configuration' => self::discovery(),
            'https://idp.test/k' => json_encode(['keys' => [['kty' => 'RSA', 'kid' => 'k1', 'n' => $b64($details['n']), 'e' => $b64($details['e'])]]]),
        ]);
        $this->logout = new ProviderLogout(new MemoryReplays(), $this->history, $this->sessions, new SamlBroker($clock), new OidcBroker($http, new MemoryCache(), $clock));
    }

    protected function tearDown(): void
    {
        $_GET = [];
        unset($_SERVER['QUERY_STRING']);
    }

    private function saml(array $over = []): Connection
    {
        return Support::connection($over + ['acceptProviderLogout' => true, 'saml' => self::$fixture->saml(['sloUrl' => 'https://idp.test/slo'])]);
    }

    private function oidc(array $over = []): Connection
    {
        return Support::connection($over + ['protocol' => Connection::OIDC, 'acceptProviderLogout' => true, 'oidc' => ['issuer' => self::ISSUER, 'clientId' => 'client-1']]);
    }

    private function urls(): Urls
    {
        return new Urls('https://site.test', 'okta');
    }

    private function samlRefusal(?Connection $connection = null): string
    {
        try {
            $this->logout->saml($connection ?? $this->saml(), $this->urls());
        } catch (Refused $refused) {
            $this->assertSame([], $this->sessions->ended, 'A refused request must end nothing');
            return $refused->reason;
        }
        $this->fail('Logout request was accepted');
    }

    private function token(array $over = [], array $without = [], array $header = ['typ' => 'logout+jwt'], ?string $key = null): string
    {
        $claims = $over + ['iss' => self::ISSUER, 'aud' => 'client-1', 'iat' => $this->now, 'exp' => $this->now + 120, 'jti' => 'j1', 'sub' => 's1', 'sid' => 'sid1', 'events' => [self::EVENT => new \stdClass()]];
        return JWT::encode(array_diff_key($claims, array_flip($without)), $key ?? self::$rsa['private'], 'RS256', 'k1', $header);
    }

    private function oidcRefusal(string $token, ?Connection $connection = null): string
    {
        try {
            $this->logout->oidc($connection ?? $this->oidc(), $token);
        } catch (Refused $refused) {
            $this->assertSame([], $this->sessions->ended, 'A refused token must end nothing');
            return $refused->reason;
        }
        $this->fail('Logout token was accepted');
    }

    public function testT61SignedSamlRequestEndsTheNamedSession(): void
    {
        self::$fixture->logoutRequest($this->now);
        $url = $this->logout->saml($this->saml(), $this->urls());
        $this->assertStringStartsWith('https://idp.test/slo?SAMLResponse=', $url);
        $this->assertSame([['c-uid', ProviderLogout::key('c-uid', 'n1'), [ProviderLogout::key('c-uid', '_s1')]]], $this->sessions->ended);
        $this->assertSame(ProviderLogout::OUTCOME, $this->history->rows[0]['outcome']);
        $this->assertSame(7, $this->history->rows[0]['userId']);
    }

    public function testT61NoSessionIndexEndsAllOfThatPerson(): void
    {
        self::$fixture->logoutRequest($this->now, ['index' => null]);
        $this->logout->saml($this->saml(), $this->urls());
        $this->assertSame([], $this->sessions->ended[0][2]);
    }

    public function testT62UnsignedRequest(): void
    {
        self::$fixture->logoutRequest($this->now, ['sign' => false]);
        $this->assertSame('logout_invalid', $this->samlRefusal());
    }

    public function testT62RequestSignedByAnotherKey(): void
    {
        self::$fixture->logoutRequest($this->now, ['key' => self::$other['private']]);
        $this->assertSame('logout_invalid', $this->samlRefusal());
    }

    public function testT62NameChangedAfterSigning(): void
    {
        self::$fixture->logoutRequest($this->now);
        $signature = $_GET['Signature'];
        self::$fixture->logoutRequest($this->now, ['nameId' => 'victim', 'sign' => false]);
        $_GET['Signature'] = $signature;
        $_SERVER['QUERY_STRING'] .= '&Signature=' . rawurlencode($signature);
        $this->assertSame('logout_invalid', $this->samlRefusal());
    }

    /** The signature is checked over the raw query, and the message is read from PHP's parsed one. They must be the same message. */
    public function testT62SecondMessageBehindAnEncodedName(): void
    {
        self::$fixture->logoutRequest($this->now - 3600);
        $genuine = $_SERVER['QUERY_STRING'];
        $signed = $_GET;
        self::$fixture->logoutRequest($this->now, ['nameId' => 'victim', 'sign' => false]);
        $_GET = ['SAMLRequest' => $_GET['SAMLRequest']] + $signed;
        $_SERVER['QUERY_STRING'] = $genuine . '&%53AMLRequest=' . rawurlencode($_GET['SAMLRequest']);
        $this->assertSame('logout_invalid', $this->samlRefusal());
    }

    public function testT62ArrayParametersAreRefused(): void
    {
        self::$fixture->logoutRequest($this->now);
        $_GET['SigAlg'] = ['x'];
        $this->assertSame('logout_invalid', $this->samlRefusal());
    }

    public function testT67WrongProtocolIsRefused(): void
    {
        $this->assertSame('logout_off', $this->oidcRefusal($this->token(), $this->saml()));
        self::$fixture->logoutRequest($this->now);
        $this->assertSame('logout_off', $this->samlRefusal($this->oidc()));
    }

    public function testT69ArrayHeaderValuesAreRefused(): void
    {
        $b64 = static fn(string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        foreach (['{"alg":"RS256","typ":["x"]}', '{"alg":"RS256","kid":["x"]}'] as $header) {
            $this->oidcRefusal($b64($header) . '.' . $b64('{}') . '.' . $b64('x'));
        }
        $this->addToAssertionCount(1);
    }

    public function testT63Sha1Signature(): void
    {
        self::$fixture->logoutRequest($this->now, ['alg' => XMLSecurityKey::RSA_SHA1]);
        $this->assertSame('weak_algorithm', $this->samlRefusal());
    }

    public function testT64WrongIssuerAndDestination(): void
    {
        self::$fixture->logoutRequest($this->now, ['issuer' => 'https://evil.test/idp']);
        $this->assertSame('logout_invalid', $this->samlRefusal());
        self::$fixture->logoutRequest($this->now, ['destination' => 'https://other.test/portero/saml/slo/okta']);
        $this->assertSame('logout_invalid', $this->samlRefusal());
    }

    public function testT65OldRequest(): void
    {
        self::$fixture->logoutRequest($this->now - 3600);
        $this->assertSame('logout_invalid', $this->samlRefusal());
    }

    public function testT66ReplayedRequest(): void
    {
        self::$fixture->logoutRequest($this->now);
        $this->logout->saml($this->saml(), $this->urls());
        $count = count($this->sessions->ended);
        try {
            $this->logout->saml($this->saml(), $this->urls());
            $this->fail('Replay was accepted');
        } catch (Refused $refused) {
            $this->assertSame('replayed', $refused->reason);
        }
        $this->assertCount($count, $this->sessions->ended);
    }

    public function testT67OffByDefault(): void
    {
        $this->assertFalse(Support::connection()->acceptProviderLogout);
        self::$fixture->logoutRequest($this->now);
        $this->assertSame('logout_off', $this->samlRefusal($this->saml(['acceptProviderLogout' => false])));
        $this->assertSame('logout_off', $this->oidcRefusal($this->token(), $this->oidc(['acceptProviderLogout' => false])));
        $this->assertSame('logout_off', $this->history->rows[0]['reasonCode']);
    }

    public function testT67NeedsAProviderAddressToAnswer(): void
    {
        self::$fixture->logoutRequest($this->now);
        $this->assertSame('logout_off', $this->samlRefusal($this->saml(['saml' => self::$fixture->saml()])));
        $errors = ConnectionRules::check(['name' => 'x', 'handle' => 'x', 'protocol' => Connection::SAML, 'acceptProviderLogout' => true, 'saml' => []]);
        $this->assertArrayHasKey('acceptProviderLogout', $errors);
    }

    public function testT68BackChannelTokenEndsTheNamedSession(): void
    {
        $this->logout->oidc($this->oidc(), $this->token());
        $this->assertSame([['c-uid', ProviderLogout::key('c-uid', self::ISSUER . '|s1'), [ProviderLogout::key('c-uid', 'sid1')]]], $this->sessions->ended);
    }

    public function testT68SidAloneOrSubAlone(): void
    {
        $this->logout->oidc($this->oidc(), $this->token(['jti' => 'j2'], ['sub']));
        $this->assertNull($this->sessions->ended[0][1]);
        $this->logout->oidc($this->oidc(), $this->token(['jti' => 'j3'], ['sid']));
        $this->assertSame([], $this->sessions->ended[1][2]);
    }

    public function testT69WrongClaims(): void
    {
        foreach ([
            'iss' => $this->token(['iss' => 'https://evil.test']),
            'aud' => $this->token(['aud' => 'someone-else']),
            'no events' => $this->token([], ['events']),
            'wrong event' => $this->token(['events' => ['http://example.test/other' => new \stdClass()]]),
            'nonce' => $this->token(['nonce' => 'n1']),
            'no jti' => $this->token([], ['jti']),
            'no sub or sid' => $this->token([], ['sub', 'sid']),
            'typ' => $this->token([], [], ['typ' => 'JWT']),
            'old with no exp' => $this->token(['iat' => $this->now - 3600], ['exp']),
        ] as $case => $token) {
            $this->assertSame('logout_invalid', $this->oidcRefusal($token), $case);
        }
    }

    public function testT70ForgedSignatures(): void
    {
        $this->assertSame('oidc_token_invalid', $this->oidcRefusal($this->token([], [], ['typ' => 'logout+jwt'], self::$other['private'])));
        $b64 = static fn(string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $none = $b64('{"alg":"none"}') . '.' . $b64(json_encode(['iss' => self::ISSUER])) . '.';
        $this->assertSame('logout_invalid', $this->oidcRefusal($none));
        $this->assertSame('logout_invalid', $this->oidcRefusal('not-a-token'));
        $this->assertSame('logout_invalid', $this->oidcRefusal(''));
    }

    public function testT70TokenFromTheFuture(): void
    {
        $this->assertSame('oidc_token_invalid', $this->oidcRefusal($this->token(['iat' => $this->now + 3600])));
    }

    public function testT70ExpiredToken(): void
    {
        $this->assertSame('oidc_token_invalid', $this->oidcRefusal($this->token(['iat' => $this->now - 900, 'exp' => $this->now - 300])));
    }

    public function testT71ReplayedToken(): void
    {
        $token = $this->token();
        $this->logout->oidc($this->oidc(), $token);
        try {
            $this->logout->oidc($this->oidc(), $token);
            $this->fail('Replay was accepted');
        } catch (Refused $refused) {
            $this->assertSame('replayed', $refused->reason);
        }
        $this->assertCount(1, $this->sessions->ended);
    }

    private function user(array $groups, string $uid = 'u1'): UserRef
    {
        return new UserRef(5, 'elena@acme.com', 'Elena', false, false, $groups, $uid);
    }

    public function testT72GroupsByHandle(): void
    {
        $user = $this->user([]);
        $rules = new AccessRules(new MemoryUsers([$user], ['c-uid|s1' => $user]));
        $decision = $rules->decide(Support::connection(['groupsByHandle' => true]), new Identity('s1', 'elena@acme.com', groups: ['editors', 'no-such-group']));
        $this->assertSame(['editors'], $decision->addGroups);
        $this->assertSame([], $rules->decide(Support::connection(), new Identity('s1', 'elena@acme.com', groups: ['editors']))->addGroups);
    }

    public function testT72HandleCountsForRefuseIfNoMatch(): void
    {
        $user = $this->user([]);
        $rules = new AccessRules(new MemoryUsers([$user], ['c-uid|s1' => $user]));
        $connection = Support::connection(['groupsByHandle' => true, 'requireGroupMatch' => true]);
        $this->assertSame(['editors'], $rules->decide($connection, new Identity('s1', 'elena@acme.com', groups: ['editors']))->addGroups);
        $this->expectException(Refused::class);
        $rules->decide($connection, new Identity('s1', 'elena@acme.com', groups: ['no-such-group']));
    }

    public function testT73ManageAllGroups(): void
    {
        $user = $this->user(['editors', 'newsletter']);
        $rules = new AccessRules(new MemoryUsers([$user], ['c-uid|s1' => $user]));
        $identity = new Identity('s1', 'elena@acme.com', groups: ['editors']);
        $this->assertSame(['newsletter'], $rules->decide(Support::connection(['groupsByHandle' => true, 'manageAllGroups' => true]), $identity)->removeGroups);
        $this->assertSame([], $rules->decide(Support::connection(['groupsByHandle' => true]), $identity)->removeGroups);
    }

    public function testT73BreakGlassKeepsItsGroups(): void
    {
        $user = $this->user(['managers']);
        $rules = new AccessRules(new MemoryUsers([$user], ['c-uid|s1' => $user]), ['u1']);
        $this->assertSame([], $rules->decide(Support::connection(['manageAllGroups' => true]), new Identity('s1', 'elena@acme.com'))->removeGroups);
    }

    public function testT74ExtraParametersCantReplaceProtections(): void
    {
        $clock = new FixedClock($this->now);
        $http = new ScriptedHttp([self::ISSUER . '/.well-known/openid-configuration' => self::discovery()]);
        $url = (new OidcBroker($http, new MemoryCache(), $clock))->authorizationUrl($this->oidc(), 'https://site.test/cb', 'st', 'no', 'ver', false, ['state' => 'evil', 'redirect_uri' => 'https://evil.test', 'login_hint' => 'elena@acme.com']);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('st', $query['state']);
        $this->assertSame('https://site.test/cb', $query['redirect_uri']);
        $this->assertSame('elena@acme.com', $query['login_hint']);
    }
}
