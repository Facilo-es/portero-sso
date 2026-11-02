<?php

namespace facilo\portero\Core\Ports;

interface FlowStore
{
    /** @param array{connectionUid:string, kind:string, returnPath:?string, requestId:string, nonce:?string, codeVerifier:?string, bindingHash:string, expiresAt:int} $flow */
    public function save(array $flow): void;

    /** @return array{connectionUid:string, kind:string, returnPath:?string, requestId:string, nonce:?string, codeVerifier:?string, bindingHash:string, expiresAt:int, usedAt:?int}|null */
    public function find(string $requestId): ?array;

    /** Marks it used. False if another request got there first. */
    public function consume(string $requestId, int $now): bool;
}
