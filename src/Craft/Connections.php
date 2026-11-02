<?php

namespace facilo\portero\Craft;

use Craft;
use craft\db\Query;
use craft\helpers\App;
use craft\helpers\StringHelper;
use facilo\portero\Core\Connection;
use yii\base\Component;

/** Connections live in project config, so they deploy like any other setting. Secrets are only ever environment references. */
class Connections extends Component
{
    public const CONFIG_PATH = 'portero.connections';
    private const CERTIFICATE_ALERT_SECONDS = 604800;

    /** @var array<string,array>|null */
    private ?array $states = null;

    /** @return array<string,array> uid => stored values */
    public function all(): array
    {
        $all = Craft::$app->getProjectConfig()->get(self::CONFIG_PATH) ?? [];
        uasort($all, static fn(array $a, array $b) => strcasecmp($a['name'] ?? '', $b['name'] ?? ''));
        return $all;
    }

    public function raw(string $uid): ?array
    {
        return $this->all()[$uid] ?? null;
    }

    /** Stored values with environment references worked out, ready to use. */
    public function get(string $uid): ?Connection
    {
        $raw = $this->raw($uid);
        return $raw === null ? null : Connection::fromArray($uid, $this->resolved($uid, $raw));
    }

    public function byHandle(string $handle): ?Connection
    {
        foreach ($this->all() as $uid => $raw) {
            if (($raw['handle'] ?? '') === $handle) {
                return Connection::fromArray($uid, $this->resolved($uid, $raw));
            }
        }
        return null;
    }

    /** @return Connection[] */
    public function enabled(): array
    {
        $enabled = [];
        foreach ($this->all() as $uid => $raw) {
            if (!empty($raw['enabled'])) {
                $enabled[] = Connection::fromArray($uid, $this->resolved($uid, $raw));
            }
        }
        return $enabled;
    }

    /** The old plugin's addresses, kept so a provider set up for it needs no change. */
    public function legacyRoutes(): array
    {
        $routes = [];
        foreach ($this->all() as $raw) {
            $saml = $raw['saml'] ?? [];
            if (!empty($saml['legacyAcsPath'])) {
                $routes[trim($saml['legacyAcsPath'], '/')] = 'portero-sso/auth/legacy-acs';
            }
            if (!empty($saml['legacyLoginPath'])) {
                $routes[trim($saml['legacyLoginPath'], '/')] = 'portero-sso/auth/legacy-login';
            }
        }
        return $routes;
    }

    /** Several old providers can share one address, so the response's issuer picks the connection. Its signature is still checked against that connection alone. */
    public function byLegacyAcs(string $path, ?string $issuer): ?Connection
    {
        $found = null;
        foreach ($this->all() as $uid => $raw) {
            if (trim($raw['saml']['legacyAcsPath'] ?? '', '/') !== trim($path, '/')) {
                continue;
            }
            $found = $this->get($uid);
            if ($issuer !== null && ($found->saml['idpEntityId'] ?? '') === $issuer) {
                return $found;
            }
        }
        return $found;
    }

    public function byLegacyLogin(string $path): ?Connection
    {
        foreach ($this->all() as $uid => $raw) {
            if (trim($raw['saml']['legacyLoginPath'] ?? '', '/') === trim($path, '/')) {
                return $this->get($uid);
            }
        }
        return null;
    }

    public function save(string $uid, array $values): void
    {
        Craft::$app->getProjectConfig()->set(self::CONFIG_PATH . ".$uid", $values, "Portero connection: {$values['handle']}");
    }

    public function newUid(): string
    {
        return StringHelper::UUID();
    }

    public function delete(string $uid): void
    {
        Craft::$app->getDb()->createCommand()->delete('{{%portero_identities}}', ['connectionUid' => $uid])->execute();
        Craft::$app->getDb()->createCommand()->delete('{{%portero_sessions}}', ['connectionUid' => $uid])->execute();
        Craft::$app->getDb()->createCommand()->delete('{{%portero_state}}', ['connectionUid' => $uid])->execute();
        Craft::$app->getProjectConfig()->remove(self::CONFIG_PATH . ".$uid", 'Remove Portero connection');
    }

    public function people(string $uid): int
    {
        return (int) (new Query())->from('{{%portero_identities}}')->where(['connectionUid' => $uid])->count();
    }

    public function lastTestPassed(string $uid): ?int
    {
        $value = $this->state($uid)['lastTestPassed'] ?? null;
        return $value ? (int) $value : null;
    }

    public function markTestPassed(string $uid): void
    {
        $this->writeState($uid, ['lastTestPassed' => time()]);
    }

    public function saveMetadata(string $uid, array $details, string $fromUrl, bool $certificatesChanged): void
    {
        $values = ['metadata' => json_encode($details + ['fromUrl' => $fromUrl]), 'metadataFetched' => time(), 'metadataFailed' => null];
        if ($certificatesChanged) {
            $values['certificatesChanged'] = time();
        }
        $this->writeState($uid, $values);
    }

    /** Step 3 has just stored details of its own, so an older fetched copy mustn't sit on top of them. */
    public function clearMetadata(string $uid): void
    {
        $this->writeState($uid, ['metadata' => null, 'metadataFailed' => null]);
    }

    public function certificatesChangedRecently(string $uid): bool
    {
        return (int) ($this->state($uid)['certificatesChanged'] ?? 0) > time() - self::CERTIFICATE_ALERT_SECONDS;
    }

    public function markMetadataFailed(string $uid): void
    {
        $this->writeState($uid, ['metadataFailed' => time()]);
    }

    public function metadataFailed(string $uid): bool
    {
        return !empty($this->state($uid)['metadataFailed']);
    }

    private function state(string $uid): array
    {
        $this->states ??= (new Query())->from('{{%portero_state}}')->indexBy('connectionUid')->all();
        return $this->states[$uid] ?? [];
    }

    private function writeState(string $uid, array $values): void
    {
        Craft::$app->getDb()->createCommand()->upsert('{{%portero_state}}', ['connectionUid' => $uid] + $values, $values, [], false)->execute();
        $this->states = null;
    }

    /** @param string|null $without a connection about to be turned off or deleted */
    public function anyTestPassed(?string $without = null): bool
    {
        foreach ($this->enabled() as $connection) {
            if ($connection->uid !== $without && $this->lastTestPassed($connection->uid)) {
                return true;
            }
        }
        return false;
    }

    /** Days until the soonest provider certificate expires. Null for OpenID Connect, or if there's none to read. */
    public function certificateDays(Connection $connection): ?int
    {
        $soonest = null;
        foreach ($connection->saml['certs'] ?? [] as $cert) {
            $expires = $this->expires((string) $cert);
            $soonest = $expires === null ? $soonest : min($soonest ?? PHP_INT_MAX, $expires);
        }
        return $soonest === null ? null : (int) floor(($soonest - time()) / 86400);
    }

    /** Days until this site's own certificate expires. Null if it has none. */
    public function ownCertificateDays(Connection $connection): ?int
    {
        $keys = $this->spKeys($connection);
        $expires = isset($keys['cert']) ? $this->expires($keys['cert']) : null;
        return $expires === null ? null : (int) floor(($expires - time()) / 86400);
    }

    /** @return array{key?:string, cert?:string} the key pair behind this connection's two environment references */
    public function spKeys(Connection $connection): array
    {
        $keys = [];
        foreach (['key' => 'spPrivateKey', 'cert' => 'spCertificate'] as $name => $setting) {
            $reference = (string) ($connection->saml[$setting] ?? '');
            $value = $reference !== '' ? base64_decode((string) App::parseEnv($reference), true) : false;
            if ($value !== false && $value !== '' && str_contains($value, '-----BEGIN')) {
                $keys[$name] = $value;
            }
        }
        return $keys;
    }

    private function expires(string $cert): ?int
    {
        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(preg_replace('/\s+|-----[A-Z ]+-----/', '', $cert), 64) . "-----END CERTIFICATE-----\n";
        $parsed = @openssl_x509_parse($pem);
        return $parsed && isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null;
    }

    private function resolved(string $uid, array $raw): array
    {
        foreach (['ssoUrl', 'sloUrl', 'idpEntityId', 'spEntityId'] as $key) {
            if (isset($raw['saml'][$key])) {
                $raw['saml'][$key] = App::parseEnv($raw['saml'][$key]);
            }
        }
        // After the environment step, so a value from a remote file is never expanded.
        $fresh = json_decode((string) ($this->state($uid)['metadata'] ?? ''), true);
        if (is_array($fresh) && ($fresh['fromUrl'] ?? '') === ($raw['saml']['metadataUrl'] ?? null)) {
            unset($fresh['fromUrl']);
            $raw['saml'] = array_merge($raw['saml'], $fresh);
        }
        foreach (['issuer', 'clientId'] as $key) {
            if (isset($raw['oidc'][$key])) {
                $raw['oidc'][$key] = App::parseEnv($raw['oidc'][$key]);
            }
        }
        return $raw;
    }
}
