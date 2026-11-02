<?php

namespace facilo\portero\Core;

final class Result
{
    public function __construct(
        public readonly string $kind,
        public readonly ?string $returnPath,
        public readonly ?Identity $identity,
        public readonly ?Decision $decision,
        public readonly ?Refused $refused = null,
    ) {
    }

    public function passed(): bool
    {
        return $this->refused === null;
    }
}
