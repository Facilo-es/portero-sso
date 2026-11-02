<?php

namespace facilo\portero\Craft\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\App;
use facilo\portero\Plugin;
use yii\console\ExitCode;
use yii\helpers\Console;

class KeysController extends Controller
{
    private const DAYS = 3650;

    /** @var bool Print the two values instead of writing them to .env. Use this where .env isn't a file, such as Craft Cloud. */
    public bool $print = false;

    /** @var bool Replace an existing key. Signed requests and encrypted assertions stop working until your provider has the new certificate. */
    public bool $force = false;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'print', 'force'];
    }

    /** Creates the key pair for signed requests and encrypted assertions on one connection. */
    public function actionGenerate(string $handle): int
    {
        $connection = Plugin::getInstance()->connections->byHandle($handle);
        if ($connection === null) {
            $this->stderr("No connection with the handle $handle.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }
        $prefix = 'PORTERO_' . strtoupper(str_replace('-', '_', $handle));
        if (!$this->force && App::env("{$prefix}_SP_KEY")) {
            $this->stderr("This connection already has a key. Replacing it stops signing and encryption until your provider has the new certificate. Use --force to replace it.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $config = Craft::$app->getConfig();
        if (!$this->print && !is_writable($config->getDotEnvPath())) {
            $this->stderr(".env isn't writable here. Use --print, and don't run it where a deploy log would keep the key.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $request = openssl_csr_new(['commonName' => mb_substr('portero-' . $handle, 0, 64)], $key, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($request, null, $key, self::DAYS, ['digest_alg' => 'sha256']);
        openssl_pkey_export($key, $keyPem);
        openssl_x509_export($certificate, $certPem);

        $values = ["{$prefix}_SP_KEY" => base64_encode($keyPem), "{$prefix}_SP_CERT" => base64_encode($certPem)];
        if ($this->print) {
            $this->stdout("Add these to every environment's secrets:\n\n", Console::FG_YELLOW);
            foreach ($values as $name => $value) {
                $this->stdout("$name=$value\n");
            }
        } else {
            foreach ($values as $name => $value) {
                $config->setDotEnvVar($name, $value);
            }
            $this->stdout('Added ' . implode(' and ', array_keys($values)) . " to .env. Copy both to every other environment.\n", Console::FG_GREEN);
        }

        $connections = Plugin::getInstance()->connections;
        if (Craft::$app->getProjectConfig()->readOnly) {
            $this->stdout('Project config is read-only here, so enter $' . "{$prefix}_SP_KEY and \${$prefix}_SP_CERT on step 3 where admin changes are on.\n");
            return ExitCode::OK;
        }
        $raw = $connections->raw($connection->uid);
        $raw['saml']['spPrivateKey'] = "\${$prefix}_SP_KEY";
        $raw['saml']['spCertificate'] = "\${$prefix}_SP_CERT";
        $connections->save($connection->uid, $raw);
        Craft::$app->getProjectConfig()->flush();
        $this->stdout("Saved the references on $handle. Turn on signed requests or encrypted assertions on step 3, and give your provider the new metadata.\n");
        return ExitCode::OK;
    }
}
