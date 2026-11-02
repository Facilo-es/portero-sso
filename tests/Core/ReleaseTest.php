<?php

namespace facilo\portero\tests\Core;

use facilo\portero\Core\Connection;
use facilo\portero\Core\ConnectionRules;
use facilo\portero\Core\OidcBroker;
use facilo\portero\Core\Refused;
use facilo\portero\Core\SamlBroker;
use facilo\portero\Core\SignIn;
use facilo\portero\Core\UrlGuard;
use facilo\portero\Core\Urls;
use PHPUnit\Framework\TestCase;

/** What was added after the prototype: key pairs, provider sign-out, the old plugin's addresses, and pinned fetches. */
final class ReleaseTest extends TestCase
{
    private function saml(array $saml, array $over = []): array
    {
        return $over + ['name' => 'Okta', 'handle' => 'okta', 'protocol' => Connection::SAML, 'saml' => $saml];
    }

    public function testKeysMustBeEnvironmentReferences(): void
    {
        $errors = ConnectionRules::check($this->saml(['spPrivateKey' => '-----BEGIN PRIVATE KEY-----', 'spCertificate' => '$CERT']));
        $this->assertArrayHasKey('spPrivateKey', $errors);
        $this->assertSame([], ConnectionRules::check($this->saml(['spPrivateKey' => '$KEY', 'spCertificate' => '$CERT'])));
    }

    public function testSigningAndEncryptionNeedAKeyPair(): void
    {
        $this->assertArrayHasKey('spPrivateKey', ConnectionRules::check($this->saml(['encryptAssertions' => true])));
        $this->assertArrayHasKey('spPrivateKey', ConnectionRules::check($this->saml(['signRequests' => true, 'nameIdFormat' => 'x', 'spPrivateKey' => '$KEY'])));
        $this->assertSame([], ConnectionRules::check($this->saml(['encryptAssertions' => true, 'spPrivateKey' => '$KEY', 'spCertificate' => '$CERT'])));
    }

    public function testPinReturnsTheCheckedAddress(): void
    {
        $resolve = static fn(string $host) => ['93.184.216.34'];
        $this->assertSame(['host' => 'idp.example', 'port' => 8443, 'ip' => '93.184.216.34'], UrlGuard::pin('https://idp.example:8443/x', $resolve));
        $this->assertNull(UrlGuard::pin('http://idp.example/x', $resolve));
        $this->assertNull(UrlGuard::pin('https://idp.example/x', static fn(string $host) => ['93.184.216.34', '10.0.0.1']));
        $this->assertNull(UrlGuard::pin('https://idp.example/x', static fn(string $host) => ['100.64.0.1']));
        $this->assertNull(UrlGuard::pin('https://idp.example/x', static fn(string $host) => ['::ffff:127.0.0.1']));
    }

    public function testLegacyAcsReplacesOnlyTheSignInAddress(): void
    {
        $urls = new Urls('https://acme.test/', 'okta', '/sso/login/abc');
        $this->assertSame('https://acme.test/sso/login/abc', $urls->acs());
        $this->assertSame('https://acme.test/portero/saml/metadata/okta', $urls->metadata());
        $this->assertSame('https://acme.test/portero/oidc/logout/okta', $urls->logout());
    }

    public function testSamlLogoutCarriesTheSubjectAndNeedsAnAddress(): void
    {
        $broker = new SamlBroker(new FixedClock(1000));
        $urls = new Urls('https://acme.test', 'okta');
        $saml = ['idpEntityId' => 'urn:idp', 'ssoUrl' => 'https://idp.test/sso', 'sloUrl' => 'https://idp.test/slo', 'certs' => [(new SamlFixture())->certBody()]];
        $url = $broker->logoutUrl(Support::connection(['saml' => $saml]), $urls, ['nameId' => 'elena', 'sessionIndex' => 's1']);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertStringStartsWith('https://idp.test/slo?', $url);
        $xml = gzinflate(base64_decode($query['SAMLRequest']));
        $this->assertStringContainsString('>elena<', $xml);
        $this->assertStringContainsString('>s1<', $xml);
        unset($saml['sloUrl']);
        $this->assertNull($broker->logoutUrl(Support::connection(['saml' => $saml]), $urls, ['nameId' => 'elena']));
        $this->assertNull($broker->logoutUrl(Support::connection(['saml' => $saml + ['sloUrl' => 'https://idp.test/slo']]), $urls, []));
    }

    public function testIssuerIsReadBeforeTrust(): void
    {
        $broker = new SamlBroker(new FixedClock(1000));
        $xml = '<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"><saml:Issuer> urn:idp </saml:Issuer></samlp:Response>';
        $this->assertSame('urn:idp', $broker->issuer(base64_encode($xml)));
        $this->assertNull($broker->issuer('not base64 !!'));
        $this->assertNull($broker->issuer(base64_encode('<!DOCTYPE x [<!ENTITY a "b">]><x>&a;</x>')));
    }

    public function testKelvinSignIsRefusedBeforeMatching(): void
    {
        $users = new MemoryUsers([new \facilo\portero\Core\UserRef(1, 'kate@acme.com', 'Kate', true, false, [], 'u1')]);
        $rules = new \facilo\portero\Core\AccessRules($users);
        $connection = Support::connection(['matchByEmail' => true, 'matchAdmins' => true]);
        foreach (["\u{212A}ate@acme.com", "kate@\u{212A}acme.com"] as $email) {
            try {
                $rules->decide($connection, new \facilo\portero\Core\Identity('s', $email));
                $this->fail('Refused expected');
            } catch (Refused $refused) {
                $this->assertSame('missing_email', $refused->reason);
            }
        }
        $this->assertSame(\facilo\portero\Core\Decision::LINK, $rules->decide($connection, new \facilo\portero\Core\Identity('s', 'Kate@acme.com'))->action);
    }

    public function testAddressGuardReadsEveryIpv6Form(): void
    {
        foreach (['0:0:0:0:0:ffff:7f00:1', '::7f00:1', '64:ff9b::7f00:1', '2002:7f00:1::', '::1', '::', 'fc00::1', 'fe80::1', '198.18.0.1', '192.0.0.8', '100.100.100.200', '169.254.169.254'] as $address) {
            $this->assertFalse(UrlGuard::allowed('https://idp.example/', static fn() => [$address]), $address);
        }
        $this->assertTrue(UrlGuard::allowed('https://idp.example/', static fn() => ['2606:4700::1111']));
        $this->assertTrue(UrlGuard::allowed('https://idp.example/', static fn() => ['::ffff:8.8.8.8']));
    }

    public function testGoogleOpenIdConnectNeedsADomainToCreateAccounts(): void
    {
        $google = ['name' => 'G', 'handle' => 'g', 'protocol' => Connection::OIDC, 'preset' => 'google', 'createAccounts' => true, 'oidc' => []];
        $this->assertArrayHasKey('allowedDomains', ConnectionRules::check($google));
        $this->assertSame([], ConnectionRules::check($google + ['allowedDomains' => ['acme.com']]));
    }

    public function testLongReturnPathIsDropped(): void
    {
        $this->assertNull(\facilo\portero\Core\ReturnPath::safe('/' . str_repeat('a', 2000)));
        $this->assertSame('/members', \facilo\portero\Core\ReturnPath::safe('/members'));
    }

    public function testConfirmationNeedsAFreshOidcSignIn(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $details = openssl_pkey_get_details($key);
        $b64 = static fn(string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $issuer = 'https://idp.test';
        $http = new ScriptedHttp([
            "$issuer/.well-known/openid-configuration" => json_encode(['issuer' => $issuer, 'authorization_endpoint' => 'https://idp.test/a', 'token_endpoint' => 'https://idp.test/t', 'jwks_uri' => 'https://idp.test/k']),
            'https://idp.test/k' => json_encode(['keys' => [['kty' => 'RSA', 'kid' => 'k1', 'use' => 'sig', 'alg' => 'RS256', 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])]]]),
        ]);
        $now = time();
        $broker = new OidcBroker($http, new MemoryCache(), new FixedClock($now));
        $connection = Support::connection(['protocol' => Connection::OIDC, 'oidc' => ['issuer' => $issuer, 'clientId' => 'c']]);
        $token = fn(array $extra) => \Firebase\JWT\JWT::encode(['iss' => $issuer, 'aud' => 'c', 'sub' => 's', 'iat' => $now, 'exp' => $now + 300, 'nonce' => 'n'] + $extra, $private, 'RS256', 'k1');

        $this->assertSame('s', explode('|', $broker->validate($connection, $token(['auth_time' => $now]), 'n', $now - 65)->subject)[1]);
        foreach ([['auth_time' => $now - 3600], []] as $claims) {
            try {
                $broker->validate($connection, $token($claims), 'n', $now - 65);
                $this->fail('Refused expected');
            } catch (Refused $refused) {
                $this->assertSame('not_fresh', $refused->reason);
            }
        }
        $this->assertStringContainsString('max_age=0', $broker->authorizationUrl($connection, 'https://site.test/cb', 's', 'n', 'v', true));
        $this->assertStringNotContainsString('max_age', $broker->authorizationUrl($connection, 'https://site.test/cb', 's', 'n', 'v'));
    }

    public function testDisabledConnectionConfirmsNothing(): void
    {
        $clock = new FixedClock(1000);
        $signIn = new SignIn(new MemoryFlows(), new MemoryReplays(), new MemoryHistory(), $clock, new \facilo\portero\Core\AccessRules(new MemoryUsers()), new MemorySession(), new SamlBroker($clock), new OidcBroker(new ScriptedHttp([]), new MemoryCache(), $clock));
        $this->expectException(Refused::class);
        $signIn->begin(Support::connection(['enabled' => false]), new Urls('https://site.test', 'okta'), SignIn::KIND_REAUTH, null);
    }
}
