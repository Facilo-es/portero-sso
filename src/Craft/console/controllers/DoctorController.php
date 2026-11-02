<?php

namespace facilo\portero\Craft\console\controllers;

use craft\console\Controller;
use facilo\portero\Core\Connection;
use facilo\portero\Craft\Rules;
use facilo\portero\Plugin;
use yii\console\ExitCode;
use yii\helpers\Console;

/** portero/doctor checks every connection and exits non-zero if anything needs attention, so it can run in monitoring. */
class DoctorController extends Controller
{
    private const WARN_DAYS = 30;

    public $defaultAction = 'index';

    public function actionIndex(): int
    {
        $plugin = Plugin::getInstance();
        $problems = 0;
        $connections = $plugin->connections->all();
        if ($connections === []) {
            $this->stdout("No connections yet.\n");
        }
        foreach (array_keys($connections) as $uid) {
            $connection = $plugin->connections->get($uid);
            $found = $this->check($connection);
            $state = $connection->enabled ? 'enabled' : 'disabled';
            $this->stdout("{$connection->handle} ({$connection->protocol}, $state): ", Console::BOLD);
            $found === [] ? $this->stdout("OK\n", Console::FG_GREEN) : $this->stdout("\n");
            foreach ($found as $problem) {
                $this->stdout("  - $problem\n", Console::FG_RED);
            }
            $problems += count($found);
        }
        foreach ($this->checkRules() as $problem) {
            $this->stdout("Sign-in rules: $problem\n", Console::FG_RED);
            $problems++;
        }
        return $problems === 0 ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /** @return string[] */
    private function check(Connection $connection): array
    {
        $plugin = Plugin::getInstance();
        $found = [];
        if ($plugin->connections->lastTestPassed($connection->uid) === null) {
            $found[] = 'has never passed a test sign-in';
        }
        if ($connection->protocol === Connection::OIDC) {
            if ($plugin->portero->secret($connection) === '' || str_starts_with($plugin->portero->secret($connection), '$')) {
                $found[] = 'its client secret environment variable is empty';
            }
            try {
                $plugin->portero->oidc()->discovery((string) ($connection->oidc['issuer'] ?? ''));
            } catch (\Throwable) {
                $found[] = "its provider's discovery address can't be read";
            }
            return $found;
        }

        $days = $plugin->connections->certificateDays($connection);
        if ($days === null) {
            $found[] = 'has no usable provider certificate';
        } elseif ($days <= self::WARN_DAYS) {
            $found[] = "provider certificate expires in $days days";
        }
        $own = $plugin->connections->ownCertificateDays($connection);
        if ($own !== null && $own <= self::WARN_DAYS) {
            $found[] = "this site's certificate expires in $own days";
        }
        if ($plugin->connections->metadataFailed($connection->uid)) {
            $found[] = "provider details couldn't be updated at the last try";
        }
        if ((!empty($connection->saml['signRequests']) || !empty($connection->saml['encryptAssertions'])) && count($plugin->connections->spKeys($connection)) < 2) {
            $found[] = "this site's key pair isn't in the environment";
        }
        return $found;
    }

    /** @return string[] */
    private function checkRules(): array
    {
        $plugin = Plugin::getInstance();
        $rules = $plugin->rules->all();
        $found = [];
        if ($rules['mode'] === Rules::EVERYONE) {
            foreach ($plugin->rules->missingForLockout($rules['breakGlassUserUids'] ?? []) as $missing) {
                $found[] = "single sign-on is required, but you need to: $missing";
            }
        }
        return $found;
    }
}
