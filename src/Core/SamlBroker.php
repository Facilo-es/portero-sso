<?php

namespace facilo\portero\Core;

use facilo\portero\Core\Ports\Clock;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\IdPMetadataParser;
use OneLogin\Saml2\LogoutRequest;
use OneLogin\Saml2\Response;
use OneLogin\Saml2\Settings;
use OneLogin\Saml2\Utils;
use OneLogin\Saml2\ValidationError;

/** SAML 2.0 through php-saml. No XML signature code lives here: only settings and the mapping of its errors to reason codes. */
final class SamlBroker
{
    private const LOGOUT_SECONDS = 600;

    public function __construct(private readonly Clock $clock)
    {
    }

    /**
     * @param array{key?:string, cert?:string} $spKeys
     * @return array{url:string, requestId:string}
     */
    public function start(Connection $connection, Urls $urls, array $spKeys = [], bool $forceAuthn = false, array $extraParams = []): array
    {
        $auth = new Auth($this->settings($connection, $urls, $spKeys), true);
        $format = $connection->saml['nameIdFormat'] ?? '';
        $url = $auth->login($connection->handle, $extraParams, $forceAuthn, false, true, $format !== '');
        return ['url' => $url, 'requestId' => $auth->getLastRequestID()];
    }

    public function spMetadata(Connection $connection, Urls $urls, array $spKeys = []): string
    {
        return (new Settings($this->settings($connection, $urls, $spKeys, true), true))->getSPMetadata();
    }

    /** The InResponseTo of a response, before anything is trusted. Used only to find the flow. */
    public function inResponseTo(string $samlResponse): ?string
    {
        $xml = base64_decode($samlResponse, true);
        if ($xml === false || $xml === '') {
            throw new Refused('bad_signature', 'not base64');
        }
        try {
            $document = Utils::loadXML(new \DOMDocument(), $xml);
        } catch (\Exception) {
            throw new Refused('bad_signature', 'unsafe or invalid XML');
        }
        if ($document === false) {
            throw new Refused('bad_signature', 'unsafe or invalid XML');
        }
        $value = $document->documentElement->getAttribute('InResponseTo');
        return $value === '' ? null : $value;
    }

    /** Who says they sent this response, before anything is trusted. Used only to pick the connection whose certificates then check it. */
    public function issuer(string $samlResponse): ?string
    {
        $xml = base64_decode($samlResponse, true);
        if ($xml === false || $xml === '') {
            return null;
        }
        try {
            $document = Utils::loadXML(new \DOMDocument(), $xml);
        } catch (\Exception) {
            return null;
        }
        if ($document === false) {
            return null;
        }
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('saml', Constants::NS_SAML);
        $issuer = $xpath->query('//saml:Issuer')->item(0);
        return $issuer === null ? null : trim($issuer->textContent);
    }

    /**
     * @param array{key?:string, cert?:string} $spKeys
     * @return array{identity:Identity, assertionHash:string, expiresAt:int}
     * @throws Refused
     */
    public function consume(Connection $connection, Urls $urls, string $samlResponse, ?string $requestId, array $spKeys = [], ?int $freshSince = null): array
    {
        $this->pointUtilsAt($urls->acs());
        try {
            $settings = new Settings($this->settings($connection, $urls, $spKeys), true);
            $response = new Response($settings, $samlResponse);
        } catch (\Throwable $e) {
            throw new Refused('bad_signature', mb_substr($e->getMessage(), 0, 160));
        }

        $document = $response->getXMLDocument();
        if ($document instanceof \DOMDocument) {
            $this->refuseWeakAlgorithms($document);
        }

        if (!$response->isValid($requestId)) {
            throw $this->refusal($response);
        }

        if ($requestId === null && $this->confirmsARequest($response)) {
            throw new Refused('unknown_request', 'started here, but the request ID was removed');
        }

        if ($freshSince !== null && $this->authenticatedAt($response) < $freshSince) {
            throw new Refused('not_fresh');
        }

        $format = (string) $response->getNameIdFormat();
        try {
            $attributes = $response->getAttributes();
        } catch (\Throwable $e) {
            throw new Refused('bad_signature', mb_substr($e->getMessage(), 0, 160));
        }
        $stable = $connection->saml['stableAttribute'] ?? '';
        $subject = (string) $response->getNameId();
        if ($format === Constants::NAMEID_TRANSIENT) {
            if ($stable === '' || !isset($attributes[$stable][0])) {
                throw new Refused('transient_nameid');
            }
            $subject = (string) $attributes[$stable][0];
        } elseif ($stable !== '' && isset($attributes[$stable][0])) {
            $subject = (string) $attributes[$stable][0];
        }

        $providerSession = $connection->signOutOfProvider
            ? array_filter(['nameId' => (string) $response->getNameId(), 'nameIdFormat' => $format, 'sessionIndex' => (string) $response->getSessionIndex()], static fn($value) => $value !== '')
            : [];
        $assertionId = (string) $response->getAssertionId();
        $expires = (int) ($response->getAssertionNotOnOrAfter() ?: $this->clock->now() + 600);

        return [
            'identity' => Identity::fromAttributes($subject, $attributes, $connection, [
                'groupsOverage' => isset($attributes['http://schemas.microsoft.com/claims/groups.link']),
                'providerSession' => $providerSession,
                'logoutName' => (string) $response->getNameId(),
                'sessionRef' => $response->getSessionIndex(),
            ]),
            'assertionHash' => hash('sha256', $connection->uid . '|' . $assertionId),
            'expiresAt' => $expires + Constants::ALLOWED_CLOCK_DRIFT * 2,
        ];
    }

    /**
     * Single Logout started from this site. Null when the provider has no logout address.
     *
     * @param array{key?:string, cert?:string} $spKeys
     * @param array<string,string> $providerSession
     */
    public function logoutUrl(Connection $connection, Urls $urls, array $providerSession, array $spKeys = []): ?string
    {
        if (($connection->saml['sloUrl'] ?? '') === '' || empty($providerSession['nameId'])) {
            return null;
        }
        return (new Auth($this->settings($connection, $urls, $spKeys), true))
            ->logout($urls->slo(), [], $providerSession['nameId'], $providerSession['sessionIndex'] ?? null, true, $providerSession['nameIdFormat'] ?? null);
    }

    /**
     * Single Logout started by the provider. Only a signed request counts, so nobody can sign a person out by sending them a link.
     *
     * @param array{key?:string, cert?:string} $spKeys
     * @param \Closure(string, string[], string, int): void $end runs once the request is proven, with the NameID, session indexes, message ID and when that ID can be forgotten
     * @return string the address that hands the person back to the provider
     * @throws Refused
     */
    public function providerLogout(Connection $connection, Urls $urls, array $spKeys, \Closure $end): string
    {
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        $this->refuseSplitQuery();
        if (preg_match('/sha-?1$/i', (string) ($_GET['SigAlg'] ?? ''))) {
            throw new Refused('weak_algorithm');
        }
        $this->pointUtilsAt($urls->slo());
        // The signature covers the query as the provider wrote it, and some providers encode it differently from PHP.
        $_SERVER['QUERY_STRING'] = $query;
        try {
            $auth = new Auth($this->settings($connection, $urls, $spKeys, false, true), true);
            $url = $auth->processSLO(false, null, true, function () use ($auth, $spKeys, $end) {
                $xml = $auth->getLastRequestXML();
                $issued = (int) strtotime((string) Utils::loadXML(new \DOMDocument(), $xml)->documentElement->getAttribute('IssueInstant'));
                $earliest = $this->clock->now() - self::LOGOUT_SECONDS - Constants::ALLOWED_CLOCK_DRIFT;
                if ($issued < $earliest) {
                    throw new Refused('logout_invalid', 'too old');
                }
                $nameId = (string) LogoutRequest::getNameId($xml, $spKeys['key'] ?? null);
                if ($nameId === '') {
                    throw new Refused('logout_invalid', 'no NameID');
                }
                $end($nameId, LogoutRequest::getSessionIndexes($xml), (string) LogoutRequest::getID($xml), $issued + self::LOGOUT_SECONDS + Constants::ALLOWED_CLOCK_DRIFT * 2);
            }, true);
        } catch (Refused $refused) {
            throw $refused;
        } catch (\Throwable $e) {
            throw new Refused('logout_invalid', mb_substr($e->getMessage(), 0, 160));
        }
        if ($auth->getErrors() !== [] || !is_string($url) || $url === '') {
            throw new Refused('logout_invalid', mb_substr((string) $auth->getLastErrorReason(), 0, 160));
        }
        return $url;
    }

    /**
     * php-saml checks the signature over the raw query but reads the message from $_GET, and PHP decodes parameter names.
     * So ?SAMLRequest=<signed>&%53AMLRequest=<forged> would verify one message and act on the other. Both must be the same.
     */
    private function refuseSplitQuery(): void
    {
        foreach (['SAMLRequest', 'RelayState', 'SigAlg', 'Signature'] as $name) {
            if (isset($_GET[$name]) && (!is_string($_GET[$name]) || urldecode(Utils::extractOriginalQueryParam($name)) !== $_GET[$name])) {
                throw new Refused('logout_invalid', 'query differs');
            }
        }
    }

    /** @return array{idpEntityId:string, ssoUrl:string, sloUrl:string, certs:string[]} */
    public function parseIdpMetadata(string $xml): array
    {
        $parsed = IdPMetadataParser::parseXML($xml)['idp'] ?? [];
        if (empty($parsed['entityId'])) {
            throw new \InvalidArgumentException('No identity provider found in this metadata.');
        }
        $certs = $parsed['x509certMulti']['signing'] ?? array_filter([$parsed['x509cert'] ?? null]);
        return [
            'idpEntityId' => $parsed['entityId'],
            'ssoUrl' => $parsed['singleSignOnService']['url'] ?? '',
            'sloUrl' => $parsed['singleLogoutService']['url'] ?? '',
            'certs' => array_values(array_unique($certs)),
        ];
    }

    private function settings(Connection $connection, Urls $urls, array $spKeys, bool $forMetadata = false, bool $forProviderLogout = false): array
    {
        $saml = $connection->saml;
        $encrypted = !empty($saml['encryptAssertions']);
        $signed = !empty($saml['signRequests']);
        $sp = [
            'entityId' => $saml['spEntityId'] ?? $urls->metadata(),
            'assertionConsumerService' => ['url' => $urls->acs()],
            'singleLogoutService' => ['url' => $urls->slo()],
            'NameIDFormat' => ($saml['nameIdFormat'] ?? '') !== '' ? $saml['nameIdFormat'] : Constants::NAMEID_UNSPECIFIED,
        ];
        if (!empty($spKeys['cert'])) {
            $sp['x509cert'] = $spKeys['cert'];
        }
        if (!empty($spKeys['key'])) {
            $sp['privateKey'] = $spKeys['key'];
        }
        $idp = [
            'entityId' => $saml['idpEntityId'] ?? 'urn:portero:unset',
            'singleSignOnService' => ['url' => ($saml['ssoUrl'] ?? '') !== '' ? $saml['ssoUrl'] : 'https://unset.invalid/'],
            'x509certMulti' => ['signing' => array_values($saml['certs'] ?? [])],
        ];
        if (($saml['sloUrl'] ?? '') !== '') {
            $idp['singleLogoutService'] = ['url' => $saml['sloUrl']];
        }
        return [
            'strict' => true,
            'debug' => false,
            'sp' => $sp,
            'idp' => $idp,
            'security' => [
                'authnRequestsSigned' => $signed && !$forMetadata,
                'logoutRequestSigned' => $signed && !$forMetadata,
                'logoutResponseSigned' => $signed && $forProviderLogout,
                'wantMessagesSigned' => $forProviderLogout,
                'wantAssertionsSigned' => true,
                'wantAssertionsEncrypted' => $encrypted,
                'wantNameId' => true,
                'wantXMLValidation' => true,
                'requestedAuthnContext' => false,
                'destinationStrictlyMatches' => true,
                'rejectUnsolicitedResponsesWithInResponseTo' => true,
                'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
                'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
            ],
        ];
    }

    /** php-saml reads the current address from globals, and behind a proxy those aren't what the provider was told. */
    private function pointUtilsAt(string $acs): void
    {
        $parts = parse_url($acs);
        Utils::setSelfProtocol($parts['scheme']);
        Utils::setSelfHost($parts['host']);
        Utils::setSelfPort($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80));
        Utils::setBaseURLPath(null);
        $_SERVER['REQUEST_URI'] = $parts['path'];
        $_SERVER['QUERY_STRING'] = '';
        $_SERVER['SCRIPT_NAME'] = $parts['path'];
        unset($_SERVER['PATH_INFO']);
    }

    /** php-saml 4.3 has no rejectDeprecatedAlgorithm option, so SHA-1 is refused here, before anything else is looked at. */
    private function refuseWeakAlgorithms(\DOMDocument $document): void
    {
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        foreach ($xpath->query('//ds:SignatureMethod/@Algorithm | //ds:DigestMethod/@Algorithm') as $algorithm) {
            if (preg_match('/sha-?1$/i', $algorithm->value)) {
                throw new Refused('weak_algorithm');
            }
        }
    }

    private function refusal(Response $response): Refused
    {
        $error = $response->getErrorException();
        $code = $error?->getCode();
        $detail = mb_substr((string) $response->getError(), 0, 160);
        $reason = match ($code) {
            ValidationError::NO_SIGNED_ASSERTION, ValidationError::NO_SIGNATURE_FOUND, ValidationError::NO_SIGNED_MESSAGE => 'unsigned_assertion',
            ValidationError::ASSERTION_TOO_EARLY, ValidationError::ASSERTION_EXPIRED, ValidationError::RESPONSE_EXPIRED, ValidationError::SESSION_EXPIRED => 'expired_assertion',
            ValidationError::WRONG_AUDIENCE => 'wrong_audience',
            ValidationError::WRONG_DESTINATION, ValidationError::EMPTY_DESTINATION => 'wrong_destination',
            ValidationError::WRONG_INRESPONSETO => 'unknown_request',
            ValidationError::STATUS_CODE_IS_NOT_SUCCESS => 'idp_error',
            ValidationError::WRONG_SUBJECTCONFIRMATION => $this->confirmationExpired($response) ? 'expired_assertion' : 'wrong_destination',
            default => 'bad_signature',
        };
        return new Refused($reason, $detail);
    }

    /** When the provider says the person last proved who they are. Read only after the response is verified. */
    private function authenticatedAt(Response $response): int
    {
        $xpath = new \DOMXPath($response->getXMLDocument());
        $xpath->registerNamespace('saml', Constants::NS_SAML);
        $instant = $xpath->query('//saml:Assertion/saml:AuthnStatement/@AuthnInstant')->item(0);
        return $instant === null ? 0 : (int) strtotime($instant->value);
    }

    private function confirmsARequest(Response $response): bool
    {
        $xpath = new \DOMXPath($response->getXMLDocument());
        $xpath->registerNamespace('saml', Constants::NS_SAML);
        return $xpath->query('//saml:SubjectConfirmationData/@InResponseTo')->length > 0;
    }

    private function confirmationExpired(Response $response): bool
    {
        $xpath = new \DOMXPath($response->getXMLDocument());
        $xpath->registerNamespace('saml', Constants::NS_SAML);
        foreach ($xpath->query('//saml:SubjectConfirmationData/@NotOnOrAfter') as $limit) {
            if (strtotime($limit->value) < $this->clock->now() - Constants::ALLOWED_CLOCK_DRIFT) {
                return true;
            }
        }
        return false;
    }
}
