<?php

namespace facilo\portero\tests\Core;

use facilo\portero\Core\Connection;
use facilo\portero\Core\Decision;
use facilo\portero\Core\Identity;
use facilo\portero\Core\Ports\Clock;
use facilo\portero\Core\Ports\FlowStore;
use facilo\portero\Core\Ports\HistorySink;
use facilo\portero\Core\Ports\HttpClient;
use facilo\portero\Core\Ports\KeyCache;
use facilo\portero\Core\Ports\ReplayStore;
use facilo\portero\Core\Ports\SessionGate;
use facilo\portero\Core\Ports\SessionStore;
use facilo\portero\Core\Ports\UserDirectory;
use facilo\portero\Core\UserRef;

final class Support
{
    public static function connection(array $over = []): Connection
    {
        return Connection::fromArray('c-uid', $over + [
            'name' => 'Okta', 'handle' => 'okta', 'enabled' => true, 'preset' => 'okta', 'protocol' => Connection::SAML,
            'allowedDomains' => ['acme.com'], 'createAccounts' => true,
        ]);
    }
}

final class FixedClock implements Clock
{
    public function __construct(public int $now)
    {
    }

    public function now(): int
    {
        return $this->now;
    }
}

final class MemoryUsers implements UserDirectory
{
    /** @param UserRef[] $users */
    public function __construct(public array $users = [], public array $links = [], public bool $full = false)
    {
    }

    public function findLinked(string $connectionUid, string $subject): ?UserRef
    {
        return $this->links["$connectionUid|$subject"] ?? null;
    }

    public function findByEmail(string $lowercaseEmail): ?UserRef
    {
        foreach ($this->users as $user) {
            if (mb_strtolower($user->email) === $lowercaseEmail) {
                return $user;
            }
        }
        return null;
    }

    public function atUserLimit(): bool
    {
        return $this->full;
    }

    public function groupHandles(): array
    {
        return ['editors', 'managers', 'newsletter'];
    }
}

final class MemorySessions implements SessionStore
{
    public array $ended = [];

    public function end(string $connectionUid, ?string $nameHash, array $refHashes): array
    {
        $this->ended[] = [$connectionUid, $nameHash, $refHashes];
        return [7];
    }
}

final class MemoryFlows implements FlowStore
{
    public array $flows = [];

    public function save(array $flow): void
    {
        $this->flows[$flow['requestId']] = $flow + ['usedAt' => null];
    }

    public function find(string $requestId): ?array
    {
        return $this->flows[$requestId] ?? null;
    }

    public function consume(string $requestId, int $now): bool
    {
        if ($this->flows[$requestId]['usedAt'] !== null) {
            return false;
        }
        $this->flows[$requestId]['usedAt'] = $now;
        return true;
    }
}

final class MemoryReplays implements ReplayStore
{
    public array $seen = [];

    public function remember(string $assertionHash, int $expiresAt): bool
    {
        if (isset($this->seen[$assertionHash])) {
            return false;
        }
        $this->seen[$assertionHash] = $expiresAt;
        return true;
    }
}

final class MemoryHistory implements HistorySink
{
    public array $rows = [];

    public function record(array $attempt): void
    {
        $this->rows[] = $attempt;
    }
}

final class MemorySession implements SessionGate
{
    public int $applied = 0;

    public function __construct(public bool $someoneElse = false)
    {
    }

    public function signedInAsSomeoneElse(Decision $decision): bool
    {
        return $this->someoneElse;
    }

    public function apply(Decision $decision, Identity $identity, string $connectionUid): int
    {
        $this->applied++;
        return 7;
    }
}

final class MemoryCache implements KeyCache
{
    public array $values = [];

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $seconds): void
    {
        $this->values[$key] = $value;
    }
}

final class ScriptedHttp implements HttpClient
{
    public array $gets = [];
    public array $posts = [];

    /** @param array<string,callable|string> $routes */
    public function __construct(public array $routes)
    {
    }

    public function get(string $url): array
    {
        $this->gets[] = $url;
        $body = $this->routes[$url] ?? null;
        return ['status' => $body === null ? 404 : 200, 'body' => is_callable($body) ? $body() : (string) $body];
    }

    public function postForm(string $url, array $fields): array
    {
        $this->posts[] = $fields;
        $body = $this->routes[$url] ?? null;
        return ['status' => $body === null ? 400 : 200, 'body' => is_callable($body) ? $body($fields) : (string) $body];
    }
}
