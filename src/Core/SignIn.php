<?php

namespace facilo\portero\Core;

use facilo\portero\Core\Ports\Clock;
use facilo\portero\Core\Ports\FlowStore;
use facilo\portero\Core\Ports\HistorySink;
use facilo\portero\Core\Ports\ReplayStore;
use facilo\portero\Core\Ports\SessionGate;

/** One sign-in from start to finish, for either protocol. Every attempt ends in Login history. */
final class SignIn
{
    public const KIND_LOGIN = 'login';
    public const KIND_TEST = 'test';
    public const KIND_REAUTH = 'reauth';
    private const FLOW_SECONDS = 600;
    private const CLOCK_SECONDS = 60;

    public function __construct(
        private readonly FlowStore $flows,
        private readonly ReplayStore $replays,
        private readonly HistorySink $history,
        private readonly Clock $clock,
        private readonly AccessRules $rules,
        private readonly SessionGate $session,
        private readonly SamlBroker $saml,
        private readonly OidcBroker $oidc,
    ) {
    }

    /**
     * @return array{url:string, binding:string} Send the person to url, with binding in the portero_bind cookie.
     * @throws Refused
     */
    public function begin(Connection $connection, Urls $urls, string $kind, ?string $returnPath, ?string $binding = null, array $spKeys = [], array $extraParams = []): array
    {
        $binding ??= bin2hex(random_bytes(32));
        if ($kind !== self::KIND_TEST && !$connection->enabled) {
            throw new Refused('connection_disabled');
        }
        $force = $kind === self::KIND_REAUTH;
        $nonce = $verifier = null;
        if ($connection->protocol === Connection::SAML) {
            $start = $this->saml->start($connection, $urls, $spKeys, $force, $extraParams);
            $url = $start['url'];
            $requestId = $start['requestId'];
        } else {
            $requestId = bin2hex(random_bytes(24));
            $nonce = bin2hex(random_bytes(24));
            $verifier = bin2hex(random_bytes(32));
            $url = $this->oidc->authorizationUrl($connection, $urls->redirect(), $requestId, $nonce, $verifier, $force, $extraParams);
        }
        $this->flows->save([
            'connectionUid' => $connection->uid,
            'kind' => $kind,
            'returnPath' => ReturnPath::safe($returnPath),
            'requestId' => $requestId,
            'nonce' => $nonce,
            'codeVerifier' => $verifier,
            'bindingHash' => hash('sha256', $binding),
            'expiresAt' => $this->clock->now() + self::FLOW_SECONDS,
        ]);
        return ['url' => $url, 'binding' => $binding];
    }

    public function finishSaml(Connection $connection, Urls $urls, string $samlResponse, ?string $binding, array $spKeys = [], bool $allowedHere = true): Result
    {
        $kind = self::KIND_LOGIN;
        $flow = null;
        return $this->guarded($connection, $kind, $flow, function () use ($connection, $urls, $samlResponse, $binding, $spKeys, $allowedHere, &$kind, &$flow) {
            $inResponseTo = $this->saml->inResponseTo($samlResponse);
            if ($inResponseTo === null) {
                if (empty($connection->saml['allowIdpInitiated'])) {
                    throw new Refused('idp_initiated_off');
                }
                if (!$allowedHere) {
                    throw new Refused('wrong_site');
                }
                $consumed = $this->saml->consume($connection, $urls, $samlResponse, null, $spKeys);
                if (!$this->replays->remember($consumed['assertionHash'], $consumed['expiresAt'])) {
                    throw new Refused('replayed', email: $consumed['identity']->email);
                }
                return $consumed['identity'];
            }

            $flow = $this->flows->find($inResponseTo);
            if ($flow === null || $flow['connectionUid'] !== $connection->uid || $flow['expiresAt'] < $this->clock->now()) {
                throw new Refused('unknown_request');
            }
            $kind = $flow['kind'];
            $consumed = $this->saml->consume($connection, $urls, $samlResponse, $inResponseTo, $spKeys, $this->freshSince($flow));
            if (!$this->replays->remember($consumed['assertionHash'], $consumed['expiresAt'])) {
                throw new Refused('replayed', email: $consumed['identity']->email);
            }
            $this->checkBinding($flow, $binding);
            if (!$this->flows->consume($inResponseTo, $this->clock->now())) {
                throw new Refused('replayed', email: $consumed['identity']->email);
            }
            return $consumed['identity'];
        });
    }

    public function finishOidc(Connection $connection, Urls $urls, array $query, ?string $binding, string $secret): Result
    {
        $kind = self::KIND_LOGIN;
        $flow = null;
        return $this->guarded($connection, $kind, $flow, function () use ($connection, $urls, $query, $binding, $secret, &$kind, &$flow) {
            $state = is_string($query['state'] ?? null) ? $query['state'] : '';
            $flow = $state === '' ? null : $this->flows->find($state);
            if ($flow === null || $flow['connectionUid'] !== $connection->uid || $flow['expiresAt'] < $this->clock->now() || $flow['usedAt'] !== null) {
                throw new Refused('oidc_state');
            }
            $kind = $flow['kind'];
            $this->checkBinding($flow, $binding);
            if (!$this->flows->consume($state, $this->clock->now())) {
                throw new Refused('oidc_state');
            }
            if (!empty($query['error'])) {
                throw new Refused('idp_error', is_string($query['error']) ? mb_substr($query['error'], 0, 80) : '');
            }
            $code = is_string($query['code'] ?? null) ? $query['code'] : '';
            $token = $this->oidc->exchange($connection, $code, (string) $flow['codeVerifier'], $urls->redirect(), $secret);
            return $this->oidc->validate($connection, $token, (string) $flow['nonce'], $this->freshSince($flow));
        });
    }

    private function guarded(Connection $connection, string &$kind, ?array &$flow, \Closure $identify): Result
    {
        $identity = null;
        try {
            $identity = $identify();
            if ($kind !== self::KIND_TEST && !$connection->enabled) {
                throw new Refused('connection_disabled', email: $identity->email);
            }
            $decision = $this->rules->decide($connection, $identity);
            $userId = null;
            if ($kind === self::KIND_REAUTH && $decision->user === null) {
                throw new Refused('no_account', email: $identity->email);
            }
            if ($kind === self::KIND_LOGIN) {
                if ($this->session->signedInAsSomeoneElse($decision)) {
                    throw new Refused('already_signed_in', email: $identity->email);
                }
                $userId = $this->session->apply($decision, $identity, $connection->uid);
            }
            $this->log($connection, $kind, 'signed-in', null, $identity->email, $userId ?? $decision->user?->id);
            foreach ($decision->warnings as $warning) {
                $this->log($connection, $kind, 'warning', $warning, $identity->email, $userId);
            }
            return new Result($kind, $flow['returnPath'] ?? null, $identity, $decision);
        } catch (Refused $refused) {
            $email = $refused->email ?? $identity?->email;
            $this->log($connection, $kind, 'refused', $refused->reason, $email, null);
            return new Result($kind, $flow['returnPath'] ?? null, $identity, null, $refused);
        }
    }

    /** A confirmation must prove a sign-in made since it was asked for, allowing for the provider's clock. A flow's start is its expiry less its life. */
    private function freshSince(array $flow): ?int
    {
        return $flow['kind'] === self::KIND_REAUTH ? $flow['expiresAt'] - self::FLOW_SECONDS - self::CLOCK_SECONDS : null;
    }

    private function checkBinding(array $flow, ?string $binding): void
    {
        if ($binding === null || $binding === '' || !hash_equals($flow['bindingHash'], hash('sha256', $binding))) {
            throw new Refused('no_browser_binding');
        }
    }

    private function log(Connection $connection, string $kind, string $outcome, ?string $reason, ?string $email, ?int $userId): void
    {
        $this->history->record([
            'connectionUid' => $connection->uid,
            'protocol' => $connection->protocol,
            'stage' => $kind,
            'outcome' => $outcome,
            'reasonCode' => $reason,
            'maskedEmail' => Email::mask($email),
            'userId' => $userId,
        ]);
    }
}
