<?php

namespace facilo\portero\tests\Core;

use RobRichards\XMLSecLibs\XMLSecEnc;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/** Builds signed responses with a throwaway key, the way a provider does. Test code only. */
final class SamlFixture
{
    public const ACS = 'https://site.test/portero/saml/acs/okta';
    public const SP = 'https://site.test/portero/saml/metadata/okta';
    public const IDP = 'https://idp.test/entity';
    public const SLO = 'https://site.test/portero/saml/slo/okta';

    public string $key = '';
    public string $cert = '';

    public function __construct()
    {
        $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'idp.test'], $private, ['digest_alg' => 'sha256']);
        openssl_x509_export(openssl_csr_sign($csr, null, $private, 365, ['digest_alg' => 'sha256']), $this->cert);
        openssl_pkey_export($private, $this->key);
    }

    public function certBody(): string
    {
        return trim(preg_replace('/-----[A-Z ]+-----|\s+/', '', $this->cert));
    }

    public function saml(array $over = []): array
    {
        return $over + ['spEntityId' => self::SP, 'idpEntityId' => self::IDP, 'ssoUrl' => 'https://idp.test/sso', 'certs' => [$this->certBody()]];
    }

    public static function time(int $at): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $at);
    }

    /** @param array<string,mixed> $o */
    public function assertion(int $now, array $o = []): string
    {
        $o += [
            'id' => '_a' . bin2hex(random_bytes(8)), 'nameId' => '00u1a2b3c', 'format' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified',
            'audience' => self::SP, 'recipient' => self::ACS, 'inResponseTo' => '_req1', 'notBefore' => $now - 60, 'notAfter' => $now + 300,
            'email' => 'elena@acme.com', 'groups' => ['web-editors'], 'issuer' => self::IDP,
        ];
        $reply = $o['inResponseTo'] === null ? '' : " InResponseTo=\"{$o['inResponseTo']}\"";
        $groups = implode('', array_map(static fn($g) => "<saml:AttributeValue>$g</saml:AttributeValue>", $o['groups']));
        return '<saml:Assertion xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" xmlns:xs="http://www.w3.org/2001/XMLSchema" ID="' . $o['id'] . '" Version="2.0" IssueInstant="' . self::time($now) . '">'
            . "<saml:Issuer>{$o['issuer']}</saml:Issuer>"
            . "<saml:Subject><saml:NameID Format=\"{$o['format']}\">{$o['nameId']}</saml:NameID>"
            . '<saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer"><saml:SubjectConfirmationData NotOnOrAfter="' . self::time($o['notAfter']) . "\" Recipient=\"{$o['recipient']}\"$reply/></saml:SubjectConfirmation></saml:Subject>"
            . '<saml:Conditions NotBefore="' . self::time($o['notBefore']) . '" NotOnOrAfter="' . self::time($o['notAfter']) . "\"><saml:AudienceRestriction><saml:Audience>{$o['audience']}</saml:Audience></saml:AudienceRestriction></saml:Conditions>"
            . '<saml:AuthnStatement AuthnInstant="' . self::time($now) . '" SessionIndex="_s1"><saml:AuthnContext><saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:Password</saml:AuthnContextClassRef></saml:AuthnContext></saml:AuthnStatement>'
            . '<saml:AttributeStatement>'
            . "<saml:Attribute Name=\"email\"><saml:AttributeValue>{$o['email']}</saml:AttributeValue></saml:Attribute>"
            . "<saml:Attribute Name=\"groups\">$groups</saml:Attribute>"
            . '</saml:AttributeStatement></saml:Assertion>';
    }

    public function sign(string $xml, string $digest = XMLSecurityDSig::SHA256, string $method = XMLSecurityKey::RSA_SHA256): string
    {
        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        $root = $doc->documentElement;
        $sig = new XMLSecurityDSig();
        $sig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        $sig->addReference($root, $digest, ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::EXC_C14N], ['id_name' => 'ID', 'overwrite' => false]);
        $key = new XMLSecurityKey($method, ['type' => 'private']);
        $key->loadKey($this->key);
        $sig->sign($key);
        $sig->add509Cert($this->cert);
        $sig->insertSignature($root, $root->firstChild->nextSibling);
        return $doc->saveXML($root);
    }

    /** A signed response around an unsigned assertion: Keycloak's default, which the spec refuses. */
    public function signedResponse(int $now, string $assertionXml): string
    {
        return base64_encode($this->sign(base64_decode($this->response($now, $assertionXml))));
    }

    /** Wraps a signed assertion for the given certificate, as a provider does when encryption is on. */
    public function encrypt(string $signedAssertion, string $spCert): string
    {
        $doc = new \DOMDocument();
        $doc->loadXML($signedAssertion);
        $enc = new XMLSecEnc();
        $enc->setNode($doc->documentElement);
        $enc->type = XMLSecEnc::Element;
        $key = new XMLSecurityKey(XMLSecurityKey::AES256_CBC);
        $key->generateSessionKey();
        $rsa = new XMLSecurityKey(XMLSecurityKey::RSA_OAEP_MGF1P, ['type' => 'public']);
        $rsa->loadKey($spCert, false, true);
        $enc->encryptKey($rsa, $key);
        $data = $enc->encryptNode($key, false);
        return '<saml:EncryptedAssertion xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion">' . $data->ownerDocument->saveXML($data) . '</saml:EncryptedAssertion>';
    }

    public function response(int $now, string $assertionXml, array $o = []): string
    {
        $o += ['destination' => self::ACS, 'inResponseTo' => '_req1', 'status' => 'urn:oasis:names:tc:SAML:2.0:status:Success'];
        $reply = $o['inResponseTo'] === null ? '' : " InResponseTo=\"{$o['inResponseTo']}\"";
        return base64_encode('<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="_r' . bin2hex(random_bytes(8)) . '" Version="2.0" IssueInstant="' . self::time($now) . "\" Destination=\"{$o['destination']}\"$reply>"
            . '<saml:Issuer>' . self::IDP . "</saml:Issuer><samlp:Status><samlp:StatusCode Value=\"{$o['status']}\"/></samlp:Status>$assertionXml</samlp:Response>");
    }

    /** A provider's LogoutRequest on the HTTP-Redirect binding, placed in the request as PHP would see it. */
    public function logoutRequest(int $now, array $o = []): void
    {
        $o += ['destination' => self::SLO, 'issuer' => self::IDP, 'nameId' => 'n1', 'index' => '_s1', 'key' => $this->key, 'alg' => XMLSecurityKey::RSA_SHA256, 'sign' => true, 'id' => '_lr' . bin2hex(random_bytes(8))];
        $index = $o['index'] === null ? '' : "<samlp:SessionIndex>{$o['index']}</samlp:SessionIndex>";
        $xml = '<samlp:LogoutRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="' . $o['id'] . '" Version="2.0" IssueInstant="' . self::time($now) . "\" Destination=\"{$o['destination']}\">"
            . "<saml:Issuer>{$o['issuer']}</saml:Issuer><saml:NameID>{$o['nameId']}</saml:NameID>$index</samlp:LogoutRequest>";
        $encoded = base64_encode(gzdeflate($xml));
        $signed = 'SAMLRequest=' . rawurlencode($encoded) . '&SigAlg=' . rawurlencode($o['alg']);
        $_GET = ['SAMLRequest' => $encoded, 'SigAlg' => $o['alg']];
        if ($o['sign']) {
            $key = new XMLSecurityKey($o['alg'], ['type' => 'private']);
            $key->loadKey($o['key']);
            $_GET['Signature'] = base64_encode($key->signData($signed));
            $signed .= '&Signature=' . rawurlencode($_GET['Signature']);
        }
        $_SERVER['QUERY_STRING'] = $signed;
    }
}
