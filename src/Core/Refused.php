<?php

namespace facilo\portero\Core;

/** A sign-in that stops, with its reason code. The detail never holds a token, an assertion or a secret. */
final class Refused extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $detail = '', public readonly ?string $email = null)
    {
        parent::__construct($reason);
    }
}
