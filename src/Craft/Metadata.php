<?php

namespace facilo\portero\Craft;

use Craft;
use craft\helpers\App;
use facilo\portero\Core\Connection;
use facilo\portero\Craft\jobs\RefreshMetadata;
use facilo\portero\Plugin;
use yii\base\Component;

/**
 * Keeps each provider's details fresh from its metadata URL. The fresh copy lives in the database, because project
 * config is read-only on production, and it's laid over the stored connection when one is loaded.
 */
class Metadata extends Component
{
    private const SCHEDULED_KEY = 'portero.metadata.scheduled';
    private const DAY = 86400;

    /** @return array<string,bool> connection handle => refreshed */
    public function refreshAll(): array
    {
        $results = [];
        $connections = Plugin::getInstance()->connections;
        foreach ($connections->all() as $uid => $raw) {
            if (($raw['protocol'] ?? '') === Connection::SAML && ($raw['saml']['metadataUrl'] ?? '') !== '') {
                $results[$raw['handle']] = $this->refresh($uid, $raw);
            }
        }
        return $results;
    }

    /** A failed fetch keeps the last good copy. */
    private function refresh(string $uid, array $raw): bool
    {
        $connections = Plugin::getInstance()->connections;
        $current = $connections->get($uid);
        try {
            $reply = (new CraftHttp())->get((string) App::parseEnv($raw['saml']['metadataUrl']));
            if ($reply['status'] !== 200) {
                throw new \RuntimeException("status {$reply['status']}");
            }
            $details = Plugin::getInstance()->portero->saml()->parseIdpMetadata($reply['body']);
            if ($details['certs'] === [] || $details['ssoUrl'] === '') {
                throw new \RuntimeException('metadata has no certificate or sign-in address');
            }
            if ($details['idpEntityId'] !== ($current->saml['idpEntityId'] ?? '')) {
                throw new \RuntimeException('metadata names a different provider');
            }
        } catch (\Throwable $e) {
            Craft::warning("Portero: metadata for {$raw['handle']} wasn't updated: " . $e->getMessage(), __METHOD__);
            $connections->markMetadataFailed($uid);
            return false;
        }
        $changed = array_diff($details['certs'], $current->saml['certs'] ?? []) !== [];
        $connections->saveMetadata($uid, $details, $raw['saml']['metadataUrl'], $changed);
        if ($changed) {
            (new DbHistory())->record([
                'connectionUid' => $uid,
                'protocol' => Connection::SAML,
                'stage' => 'metadata',
                'outcome' => 'warning',
                'reasonCode' => 'certs_changed',
                'maskedEmail' => null,
                'userId' => null,
            ]);
        }
        return true;
    }

    /** Called from control panel requests and garbage collection, so a site with any activity keeps the daily job alive. */
    public function schedule(): void
    {
        $cache = Craft::$app->getCache();
        if ($cache->get(self::SCHEDULED_KEY)) {
            return;
        }
        $cache->set(self::SCHEDULED_KEY, 1, self::DAY + 3600);
        Craft::$app->getQueue()->push(new RefreshMetadata());
    }

    public function scheduleNext(): void
    {
        Craft::$app->getCache()->set(self::SCHEDULED_KEY, 1, self::DAY + 3600);
        Craft::$app->getQueue()->delay(self::DAY)->push(new RefreshMetadata());
    }
}
