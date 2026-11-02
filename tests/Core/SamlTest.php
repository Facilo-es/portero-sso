<?php

namespace facilo\portero\tests\Core;

use facilo\portero\Core\AccessRules;
use facilo\portero\Core\OidcBroker;
use facilo\portero\Core\Refused;
use facilo\portero\Core\SamlBroker;
use facilo\portero\Core\SignIn;
use facilo\portero\Core\Urls;
use OneLogin\Saml2\Constants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/** Test numbers are from spec section 9. Real time is used, because php-saml reads the system clock. */
final class SamlTest extends TestCase
{
    private static SamlFixture $idp;
    private int $now;
    private SamlBroker $broker;
    private Urls $urls;

    public static function setUpBeforeClass(): void
    {
        self::$idp = new SamlFixture();
    }

    protected function setUp(): void
    {
        $this->now = time();
        $this->broker = new SamlBroker(new FixedClock($this->now));
        $this->urls = new Urls('https://site.test', 'okta');
    }

    private function connection(array $saml = []): \facilo\portero\Core\Connection
    {
        return Support::connection(['saml' => self::$idp->saml($saml)]);
    }

    private function good(array $assertion = [], array $response = []): string
    {
        return self::$idp->response($this->now, self::$idp->sign(self::$idp->assertion($this->now, $assertion)), $response);
    }

    private function reason(string $response, array $saml = [], ?string $request = '_req1'): ?string
    {
        try {
            $this->broker->consume($this->connection($saml), $this->urls, $response, $request);
            return null;
        } catch (Refused $refused) {
            return $refused->reason;
        }
    }

    public function testValidAssertionIsAccepted(): void
    {
        $out = $this->broker->consume($this->connection(), $this->urls, $this->good(), '_req1');
        $this->assertSame('00u1a2b3c', $out['identity']->subject);
        $this->assertSame('elena@acme.com', $out['identity']->email);
        $this->assertSame(['web-editors'], $out['identity']->groups);
    }

    public function testT3UnsignedAssertion(): void
    {
        $this->assertSame('unsigned_assertion', $this->reason(self::$idp->response($this->now, self::$idp->assertion($this->now))));
    }

    public function testSignedResponseDoesNotReplaceSignedAssertion(): void
    {
        $this->assertSame('unsigned_assertion', $this->reason(self::$idp->signedResponse($this->now, self::$idp->assertion($this->now))));
    }

    public function testT4ChangedAfterSigning(): void
    {
        $xml = str_replace('elena@acme.com', 'admin@acme.com', self::$idp->sign(self::$idp->assertion($this->now)));
        $this->assertSame('bad_signature', $this->reason(self::$idp->response($this->now, $xml)));
    }

    public function testT4SignedByAnotherKey(): void
    {
        $other = new SamlFixture();
        $this->assertSame('bad_signature', $this->reason(self::$idp->response($this->now, $other->sign(self::$idp->assertion($this->now)))));
    }

    /** The standard wrapping shapes: a forged assertion beside or around the signed one. */
    public static function wrappings(): array
    {
        return ['forged first' => [0], 'forged last' => [1], 'signed wrapped in Extensions' => [2], 'forged, with the signature moved in' => [3]];
    }

    #[DataProvider('wrappings')]
    public function testT5SignatureWrapping(int $shape): void
    {
        $signed = self::$idp->sign(self::$idp->assertion($this->now, ['id' => '_good']));
        $forged = self::$idp->assertion($this->now, ['id' => '_evil', 'email' => 'admin@acme.com', 'nameId' => 'admin']);
        $body = match ($shape) {
            0 => $forged . $signed,
            1 => $signed . $forged,
            2 => "<samlp:Extensions>$signed</samlp:Extensions>$forged",
            3 => str_replace('</saml:Issuer><saml:Subject>', '</saml:Issuer>' . self::signature($signed) . '<saml:Subject>', $forged),
        };
        $this->assertNotNull($this->reason(self::$idp->response($this->now, $body)));
    }

    private static function signature(string $signed): string
    {
        preg_match('#<ds:Signature.*</ds:Signature>#s', $signed, $match);
        return $match[0];
    }

    public function testT6CommentInNameIdNeverShortens(): void
    {
        try {
            $out = $this->broker->consume($this->connection(), $this->urls, $this->good(['nameId' => 'admin@acme.com<!-- -->.evil.io']), '_req1');
            $this->assertSame('admin@acme.com.evil.io', $out['identity']->subject);
        } catch (Refused) {
            $this->addToAssertionCount(1);
        }
    }

    public function testT7Expired(): void
    {
        $this->assertSame('expired_assertion', $this->reason($this->good(['notBefore' => $this->now - 1200, 'notAfter' => $this->now - 600])));
    }

    public function testT8TooEarly(): void
    {
        $this->assertSame('expired_assertion', $this->reason($this->good(['notBefore' => $this->now + 600, 'notAfter' => $this->now + 1200])));
    }

    public function testT9WrongAudience(): void
    {
        $this->assertSame('wrong_audience', $this->reason($this->good(['audience' => 'https://other.test/'])));
    }

    public function testT10WrongDestination(): void
    {
        $this->assertSame('wrong_destination', $this->reason($this->good([], ['destination' => 'https://other.test/acs'])));
    }

    public function testT10WrongRecipient(): void
    {
        $this->assertSame('wrong_destination', $this->reason($this->good(['recipient' => 'https://other.test/acs'])));
    }

    public function testT12DifferentRequestId(): void
    {
        $this->assertSame('unknown_request', $this->reason($this->good(), [], '_another'));
    }

    public function testT16Sha1Signature(): void
    {
        $xml = self::$idp->sign(self::$idp->assertion($this->now), XMLSecurityDSig::SHA1, XMLSecurityKey::RSA_SHA1);
        $this->assertSame('weak_algorithm', $this->reason(self::$idp->response($this->now, $xml)));
    }

    public function testT17ExternalEntityRefused(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY x SYSTEM "http://127.0.0.1:9/evil">]><samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol">&x;</samlp:Response>';
        try {
            $this->broker->inResponseTo(base64_encode($xml));
            $this->fail('not refused');
        } catch (Refused $refused) {
            $this->assertSame('bad_signature', $refused->reason);
        }
        $this->assertSame('bad_signature', $this->reason(base64_encode($xml)));
    }

    public function testT18EncryptedAssertion(): void
    {
        $sp = new SamlFixture();
        $connection = Support::connection(['saml' => self::$idp->saml(['encryptAssertions' => true])]);
        $xml = self::$idp->encrypt(self::$idp->sign(self::$idp->assertion($this->now)), $sp->cert);
        $out = $this->broker->consume($connection, $this->urls, self::$idp->response($this->now, $xml), '_req1', ['key' => $sp->key, 'cert' => $sp->cert]);
        $this->assertSame('elena@acme.com', $out['identity']->email);
    }

    public function testT18EncryptionRequiredRefusesPlain(): void
    {
        $sp = new SamlFixture();
        $connection = Support::connection(['saml' => self::$idp->saml(['encryptAssertions' => true])]);
        $this->expectException(Refused::class);
        $this->broker->consume($connection, $this->urls, $this->good(), '_req1', ['key' => $sp->key, 'cert' => $sp->cert]);
    }

    public function testT19TransientNameId(): void
    {
        $this->assertSame('transient_nameid', $this->reason($this->good(['format' => Constants::NAMEID_TRANSIENT])));
    }

    public function testStableAttributeRescuesTransient(): void
    {
        $out = $this->broker->consume($this->connection(['stableAttribute' => 'email']), $this->urls, $this->good(['format' => Constants::NAMEID_TRANSIENT]), '_req1');
        $this->assertSame('elena@acme.com', $out['identity']->subject);
    }

    public function testProviderRefusalReported(): void
    {
        $this->assertSame('idp_error', $this->reason(self::$idp->response($this->now, '', ['status' => 'urn:oasis:names:tc:SAML:2.0:status:Responder'])));
    }

    public function testT60SignedRequestCarriesNameIdPolicy(): void
    {
        $format = Constants::NAMEID_EMAIL_ADDRESS;
        $connection = Support::connection(['saml' => self::$idp->saml(['nameIdFormat' => $format, 'signRequests' => true])]);
        $started = $this->broker->start($connection, $this->urls, ['key' => self::$idp->key, 'cert' => self::$idp->cert]);
        parse_str((string) parse_url($started['url'], PHP_URL_QUERY), $query);
        $xml = gzinflate(base64_decode($query['SAMLRequest']));
        $this->assertStringContainsString('Format="' . $format . '"', $xml);
        $this->assertStringContainsString('AssertionConsumerServiceURL="https://site.test/portero/saml/acs/okta"', $xml);
        $this->assertArrayHasKey('Signature', $query);
    }

    public function testUnsignedRequestSendsNoPolicyByDefault(): void
    {
        $started = $this->broker->start($this->connection(), $this->urls);
        parse_str((string) parse_url($started['url'], PHP_URL_QUERY), $query);
        $this->assertStringNotContainsString('NameIDPolicy', gzinflate(base64_decode($query['SAMLRequest'])));
    }

    /** @return array{0:SignIn,1:MemoryFlows,2:MemorySession,3:MemoryHistory} */
    private function flow(): array
    {
        $flows = new MemoryFlows();
        $session = new MemorySession();
        $history = new MemoryHistory();
        $clock = new FixedClock($this->now);
        $signIn = new SignIn($flows, new MemoryReplays(), $history, $clock, new AccessRules(new MemoryUsers()), $session, $this->broker, new OidcBroker(new ScriptedHttp([]), new MemoryCache(), $clock));
        return [$signIn, $flows, $session, $history];
    }

    private function saved(MemoryFlows $flows, string $hash): void
    {
        $flows->save(['connectionUid' => 'c-uid', 'kind' => 'login', 'returnPath' => null, 'requestId' => '_req1', 'nonce' => null, 'codeVerifier' => null, 'bindingHash' => hash('sha256', $hash), 'expiresAt' => $this->now + 600]);
    }

    public function testT11SecondPostIsReplayed(): void
    {
        [$signIn, $flows, $session] = $this->flow();
        $this->saved($flows, 'bind');
        $response = $this->good();
        $this->assertTrue($signIn->finishSaml($this->connection(), $this->urls, $response, 'bind')->passed());
        $this->assertSame('replayed', $signIn->finishSaml($this->connection(), $this->urls, $response, 'bind')->refused->reason);
        $this->assertSame(1, $session->applied);
    }

    public function testT13NoBindCookie(): void
    {
        [$signIn, $flows, $session] = $this->flow();
        $this->saved($flows, 'bind');
        $this->assertSame('no_browser_binding', $signIn->finishSaml($this->connection(), $this->urls, $this->good(), null)->refused->reason);
        $this->assertSame(0, $session->applied);
    }

    public function testT14ProviderStartedOff(): void
    {
        [$signIn] = $this->flow();
        $response = self::$idp->response($this->now, self::$idp->sign(self::$idp->assertion($this->now, ['inResponseTo' => null])), ['inResponseTo' => null]);
        $this->assertSame('idp_initiated_off', $signIn->finishSaml($this->connection(), $this->urls, $response, null)->refused->reason);
    }

    public function testT15ProviderStartedOnThenReplayed(): void
    {
        [$signIn] = $this->flow();
        $connection = $this->connection(['allowIdpInitiated' => true]);
        $response = self::$idp->response($this->now, self::$idp->sign(self::$idp->assertion($this->now, ['inResponseTo' => null])), ['inResponseTo' => null]);
        $this->assertTrue($signIn->finishSaml($connection, $this->urls, $response, null)->passed());
        $this->assertSame('replayed', $signIn->finishSaml($connection, $this->urls, $response, null)->refused->reason);
    }

    public function testRemovedRequestIdDoesNotDodgeBinding(): void
    {
        [$signIn] = $this->flow();
        $connection = $this->connection(['allowIdpInitiated' => true]);
        $response = self::$idp->response($this->now, self::$idp->sign(self::$idp->assertion($this->now)), ['inResponseTo' => null]);
        $this->assertSame('unknown_request', $signIn->finishSaml($connection, $this->urls, $response, null)->refused->reason);
    }

    public function testMetadataFillsTheFieldsSignInReads(): void
    {
        $xml = '<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="' . SamlFixture::IDP . '"><md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol"><md:KeyDescriptor use="signing"><ds:KeyInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#"><ds:X509Data><ds:X509Certificate>' . self::$idp->certBody() . '</ds:X509Certificate></ds:X509Data></ds:KeyInfo></md:KeyDescriptor><md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="https://idp.test/sso"/></md:IDPSSODescriptor></md:EntityDescriptor>';
        $imported = $this->broker->parseIdpMetadata($xml) + ['spEntityId' => SamlFixture::SP];
        $connection = Support::connection(['saml' => $imported]);
        $this->assertSame('elena@acme.com', $this->broker->consume($connection, $this->urls, $this->good(), '_req1')['identity']->email);
    }

    public function testExpiredFlowIsUnknown(): void
    {
        [$signIn, $flows] = $this->flow();
        $this->saved($flows, 'bind');
        $flows->flows['_req1']['expiresAt'] = $this->now - 1;
        $this->assertSame('unknown_request', $signIn->finishSaml($this->connection(), $this->urls, $this->good(), 'bind')->refused->reason);
    }

    public function testTestKindWritesNothing(): void
    {
        [$signIn, $flows, $session, $history] = $this->flow();
        $this->saved($flows, 'bind');
        $flows->flows['_req1']['kind'] = SignIn::KIND_TEST;
        $result = $signIn->finishSaml($this->connection(), $this->urls, $this->good(), 'bind');
        $this->assertTrue($result->passed());
        $this->assertSame(0, $session->applied);
        $this->assertSame('test', $history->rows[0]['stage']);
    }

    public function testDisabledConnectionRefusedAfterProviderReply(): void
    {
        [$signIn, $flows] = $this->flow();
        $this->saved($flows, 'bind');
        $connection = Support::connection(['enabled' => false, 'saml' => self::$idp->saml()]);
        $this->assertSame('connection_disabled', $signIn->finishSaml($connection, $this->urls, $this->good(), 'bind')->refused->reason);
    }

    public function testHistoryHoldsNoFullEmail(): void
    {
        [$signIn, $flows, , $history] = $this->flow();
        $this->saved($flows, 'bind');
        $signIn->finishSaml($this->connection(), $this->urls, $this->good(), 'bind');
        $this->assertSame('el***@acme.com', $history->rows[0]['maskedEmail']);
        $this->assertArrayNotHasKey('ip', $history->rows[0]);
    }

    public function testConfirmationNeedsAFreshSamlSignIn(): void
    {
        $this->assertSame('00u1a2b3c', $this->broker->consume($this->connection(), $this->urls, $this->good(), '_req1', [], $this->now - 60)['identity']->subject);
        try {
            $this->broker->consume($this->connection(), $this->urls, $this->good(), '_req1', [], $this->now + 60);
            $this->fail('Refused expected');
        } catch (Refused $refused) {
            $this->assertSame('not_fresh', $refused->reason);
        }
    }
}
