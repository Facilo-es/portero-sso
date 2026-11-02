<?php

namespace facilo\portero\Core;

use facilo\portero\Core\Ports\HistorySink;
use facilo\portero\Core\Ports\ReplayStore;
use facilo\portero\Core\Ports\SessionStore;

/** Logout started by the provider, for either protocol. Off by default on every connection, and every refusal ends in Login history. */
final class ProviderLogout
{
    public const STAGE = 'logout';
    public const OUTCOME = 'signed-out';

    public function __construct(
        private readonly ReplayStore $replays,
        private readonly HistorySink $history,
        private readonly SessionStore $sessions,
        private readonly SamlBroker $saml,
        private readonly OidcBroker $oidc,
    ) {
    }

    /** Stored instead of the value itself, so this table holds nothing that names a person. */
    public static function key(Connection|string $connection, string $value): string
    {
        return hash('sha256', ($connection instanceof Connection ? $connection->uid : $connection) . '|' . $value);
    }

    /**
     * @return string the address that hands the person back to the provider
     * @throws Refused
     */
    public function saml(Connection $connection, Urls $urls, array $spKeys = []): string
    {
        return $this->guarded($connection, function () use ($connection, $urls, $spKeys) {
            if ($connection->protocol !== Connection::SAML || ($connection->saml['sloUrl'] ?? '') === '') {
                throw new Refused('logout_off', 'no logout address');
            }
            return $this->saml->providerLogout($connection, $urls, $spKeys, function (string $nameId, array $indexes, string $id, int $expiresAt) use ($connection) {
                $this->once($connection, $id, $expiresAt);
                $this->end($connection, self::key($connection, $nameId), array_map(static fn($index) => self::key($connection, (string) $index), $indexes));
            });
        });
    }

    /** @throws Refused */
    public function oidc(Connection $connection, string $logoutToken): void
    {
        $this->guarded($connection, function () use ($connection, $logoutToken) {
            if ($connection->protocol !== Connection::OIDC) {
                throw new Refused('logout_off');
            }
            $claims = $this->oidc->logoutToken($connection, $logoutToken);
            $this->once($connection, $claims['jti'], $claims['expiresAt']);
            $this->end(
                $connection,
                $claims['name'] === null ? null : self::key($connection, $claims['name']),
                $claims['sid'] === null ? [] : [self::key($connection, $claims['sid'])],
            );
            return '';
        });
    }

    private function guarded(Connection $connection, \Closure $run): string
    {
        try {
            if (!$connection->acceptProviderLogout) {
                throw new Refused('logout_off');
            }
            return $run();
        } catch (Refused $refused) {
            $this->log($connection, 'refused', $refused->reason, null);
            throw $refused;
        }
    }

    private function once(Connection $connection, string $id, int $expiresAt): void
    {
        if (!$this->replays->remember(hash('sha256', $connection->uid . '|logout|' . $id), $expiresAt)) {
            throw new Refused('replayed');
        }
    }

    /** @param string[] $refHashes */
    private function end(Connection $connection, ?string $nameHash, array $refHashes): void
    {
        foreach ($this->sessions->end($connection->uid, $nameHash, $refHashes) as $userId) {
            $this->log($connection, self::OUTCOME, null, $userId);
        }
    }

    private function log(Connection $connection, string $outcome, ?string $reason, ?int $userId): void
    {
        $this->history->record([
            'connectionUid' => $connection->uid,
            'protocol' => $connection->protocol,
            'stage' => self::STAGE,
            'outcome' => $outcome,
            'reasonCode' => $reason,
            'maskedEmail' => null,
            'userId' => $userId,
        ]);
    }
}
