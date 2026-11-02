<?php

namespace facilo\portero\Core;

final class UserRef
{
    /** @param string[] $groups group handles */
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $name,
        public readonly bool $admin = false,
        public readonly bool $suspended = false,
        public readonly array $groups = [],
        public readonly string $uid = '',
    ) {
    }
}
