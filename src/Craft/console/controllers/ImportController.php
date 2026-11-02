<?php

namespace facilo\portero\Craft\console\controllers;

use Craft;
use craft\console\Controller;
use craft\db\Query;
use craft\helpers\Db;
use facilo\portero\Core\Connection;
use facilo\portero\Plugin;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Moves a site from SAML SSO Service Provider. Reads that plugin's two tables while they exist, and is safe to run twice.
 * The table and column names come from its public ProviderRecord and ProviderIdentityRecord classes.
 */
class ImportController extends Controller
{
    private const PROVIDERS = '{{%saml_sp_providers}}';
    private const IDENTITIES = '{{%saml_sp_provider_identity}}';

    public $defaultAction = 'saml-sp';

    /** @var bool Print what would happen, and write nothing. */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'dryRun'];
    }

    public function optionAliases(): array
    {
        return ['dry-run' => 'dryRun'];
    }

    public function actionSamlSp(): int
    {
        $db = Craft::$app->getDb();
        if ($db->getTableSchema(self::PROVIDERS) === null || $db->getTableSchema(self::IDENTITIES) === null) {
            $this->stderr("The old plugin's tables aren't here. Run this before removing it.\n", Console::FG_RED);
            return ExitCode::UNAVAILABLE;
        }
        $rows = (new Query())->from(self::PROVIDERS)->all();
        $own = array_values(array_filter($rows, static fn(array $row) => $row['providerType'] === 'sp'))[0] ?? null;
        $providers = array_filter($rows, static fn(array $row) => $row['providerType'] === 'idp');
        if ($providers === []) {
            $this->stdout("The old plugin has no identity provider to bring across.\n");
            return ExitCode::OK;
        }

        $this->dryRun && $this->stdout("Dry run: nothing is written.\n", Console::FG_YELLOW);
        $connections = Plugin::getInstance()->connections;
        $config = Craft::$app->getProjectConfig();
        $saved = false;
        foreach ($providers as $provider) {
            $uid = $this->existing((string) $provider['uid']);
            if ($uid === null) {
                if ($config->readOnly && !$this->dryRun) {
                    $this->stderr("{$provider['entityId']}: project config is read-only here. Run this where admin changes are on, deploy, then run it here for the links.\n", Console::FG_RED);
                    continue;
                }
                $values = $this->connection($provider, $own);
                $this->stdout("Connection {$values['handle']}: " . ($this->dryRun ? 'would be created' : 'created') . "\n");
                if (!$this->dryRun) {
                    $uid = $connections->newUid();
                    $connections->save($uid, $values);
                    $saved = true;
                }
            } else {
                $this->stdout('Connection ' . $connections->raw($uid)['handle'] . ": already here\n");
            }
            $this->links($provider, $uid);
            if (trim((string) ($provider['groupOptions'] ?? ''), " {}[]\n") !== '' || !empty($provider['nameIdOverride'])) {
                $this->stdout("  This provider had group allow or deny lists, or a NameID override. They aren't brought across, so people they kept out could now get in. Set group rules and Refuse sign-in if no rule matches on step 4 before removing the old plugin.\n", Console::FG_YELLOW);
            }
            if (!empty($provider['encryptAssertions'])) {
                $this->stdout("  The old plugin encrypted assertions. Its key stays with it: run portero/keys/generate, turn encryption on, and give your provider the new metadata.\n", Console::FG_YELLOW);
            }
        }
        if ($saved) {
            $config->flush();
        }
        $this->stdout("Next: press Test sign-in on each connection, add allowed domains if you want people with no link yet to be matched by email, then remove the old plugin.\n");
        return ExitCode::OK;
    }

    private function existing(string $legacyUid): ?string
    {
        foreach (Plugin::getInstance()->connections->all() as $uid => $raw) {
            if (($raw['saml']['legacyUid'] ?? '') === $legacyUid) {
                return $uid;
            }
        }
        return null;
    }

    /** Provider details come from its stored metadata, and our entity ID and sign-in address stay as the provider knows them. */
    private function connection(array $provider, ?array $own): array
    {
        $details = Plugin::getInstance()->portero->saml()->parseIdpMetadata((string) $provider['metadata']);
        $base = Plugin::getInstance()->portero->baseUrl();
        $label = trim((string) $provider['label']) ?: (string) parse_url($provider['entityId'], PHP_URL_HOST) ?: 'SAML';
        $handle = $this->handle($label);
        $acs = $own ? $this->ownAcsPath((string) $own['metadata']) : null;
        $prefix = trim((string) ($this->oldSettings()['endpointPrefix'] ?? 'sso'), '/') ?: 'sso';
        $map = Connection::defaultAttributes('saml', Connection::SAML);
        if (!empty($provider['groupsAttributeName'])) {
            $map['groups'] = $provider['groupsAttributeName'];
        }
        return [
            'name' => $label,
            'handle' => $handle,
            'enabled' => (bool) $provider['enabled'],
            'preset' => 'saml',
            'protocol' => Connection::SAML,
            'buttonLabel' => '',
            'allowedDomains' => [],
            'createAccounts' => (bool) ($this->oldSettings()['createUser'] ?? true),
            'matchByEmail' => false,
            'matchAdmins' => false,
            'updateOnSignIn' => true,
            'defaultGroups' => [],
            'groupRules' => [],
            'requireGroupMatch' => false,
            'adminRule' => '',
            'trustProvider2fa' => false,
            'maxSessionHours' => null,
            'signOutOfProvider' => false,
            'attributeMap' => $map,
            'saml' => $details + [
                'source' => 'xml',
                'spEntityId' => $own['entityId'] ?? "$base/portero/saml/$handle",
                'legacyUid' => (string) $provider['uid'],
                'legacyAcsPath' => $acs,
                'legacyLoginPath' => "$prefix/login/request/{$provider['uid']}",
                'certs' => $details['certs'],
            ],
            'oidc' => [],
        ];
    }

    private function links(array $provider, ?string $uid): void
    {
        $identities = (new Query())->from(self::IDENTITIES)->where(['providerId' => $provider['id'], 'enabled' => true])->all();
        $added = 0;
        foreach ($identities as $identity) {
            $hash = hash('sha256', (string) $identity['nameId']);
            if ($uid !== null) {
                $exists = (new Query())->from('{{%portero_identities}}')->where(['connectionUid' => $uid, 'subjectHash' => $hash])->exists();
                if ($exists) {
                    continue;
                }
            }
            $added++;
            if ($this->dryRun || $uid === null) {
                continue;
            }
            Craft::$app->getDb()->createCommand()->insert('{{%portero_identities}}', [
                'connectionUid' => $uid,
                'subject' => mb_substr((string) $identity['nameId'], 0, 512),
                'subjectHash' => $hash,
                'userId' => $identity['userId'],
                'dateCreated' => Db::prepareDateForDb(new \DateTime()),
                'lastSignIn' => $identity['lastLoginDate'],
            ], false)->execute();
        }
        $this->stdout('  Linked people: ' . count($identities) . ' on the old plugin, ' . ($this->dryRun ? "$added would be added\n" : "$added added\n"));
    }

    private function ownAcsPath(string $metadata): ?string
    {
        $document = new \DOMDocument();
        if (!@$document->loadXML($metadata, LIBXML_NONET)) {
            return null;
        }
        $service = $document->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:metadata', 'AssertionConsumerService')->item(0);
        if (!$service instanceof \DOMElement) {
            return null;
        }
        return ltrim((string) parse_url($service->getAttribute('Location'), PHP_URL_PATH), '/') ?: null;
    }

    private function oldSettings(): array
    {
        return Craft::$app->getProjectConfig()->get('plugins.saml-sp.settings') ?? [];
    }

    private function handle(string $label): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($label)), '-');
        $base = preg_replace('/^[^a-z]+/', '', $base) ?: 'saml';
        $taken = array_column(Plugin::getInstance()->connections->all(), 'handle');
        $handle = $base;
        for ($i = 2; in_array($handle, $taken, true); $i++) {
            $handle = "$base-$i";
        }
        return $handle;
    }
}
