<?php

namespace facilo\portero\Core;

/** What a sign-in would do. Test sign-in shows it, and only a real sign-in applies it. */
final class Decision
{
    public const SIGN_IN = 'sign-in';
    public const LINK = 'link';
    public const CREATE = 'create';

    /**
     * @param string[] $addGroups
     * @param string[] $removeGroups
     * @param string[] $warnings codes
     */
    public function __construct(
        public readonly string $action,
        public readonly ?UserRef $user = null,
        public readonly array $addGroups = [],
        public readonly array $removeGroups = [],
        public readonly ?bool $makeAdmin = null,
        public readonly ?string $newEmail = null,
        public readonly bool $updateDetails = false,
        public readonly array $warnings = [],
    ) {
    }
}
