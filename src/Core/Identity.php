<?php

namespace facilo\portero\Core;

/** What the provider vouched for, already mapped to our names. */
final class Identity
{
    /**
     * @param string[] $groups
     * @param array<string,string[]> $attributes every attribute received, for Test sign-in only
     * @param array<string,string> $customFields field handle => value
     * @param array<string,string> $providerSession what signing out of the provider needs later
     * @param string|null $logoutName who a logout from the provider will name
     * @param string|null $sessionRef which provider session a logout will name
     */
    public function __construct(
        public readonly string $subject,
        public readonly ?string $email,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly array $groups = [],
        public readonly ?bool $emailVerified = null,
        public readonly bool $groupsOverage = false,
        public readonly ?string $hostedDomain = null,
        public readonly array $attributes = [],
        public readonly array $customFields = [],
        public readonly array $providerSession = [],
        public readonly ?string $logoutName = null,
        public readonly ?string $sessionRef = null,
    ) {
    }

    /**
     * @param array<string,string[]> $attributes
     */
    public static function fromAttributes(string $subject, array $attributes, Connection $connection, array $extra = []): self
    {
        $map = $connection->attributeMap;
        $one = static fn(string $key): ?string => isset($map[$key], $attributes[$map[$key]][0]) ? (string) $attributes[$map[$key]][0] : null;
        $custom = [];
        foreach ($map['custom'] ?? [] as $handle => $name) {
            if (isset($attributes[$name][0])) {
                $custom[$handle] = (string) $attributes[$name][0];
            }
        }
        return new self(
            $subject,
            $one('email'),
            $one('firstName'),
            $one('lastName'),
            array_values(array_map('strval', $attributes[$map['groups'] ?? ''] ?? [])),
            $extra['emailVerified'] ?? null,
            $extra['groupsOverage'] ?? false,
            $extra['hostedDomain'] ?? null,
            $attributes,
            $custom,
            $extra['providerSession'] ?? [],
            $extra['logoutName'] ?? null,
            $extra['sessionRef'] ?? null,
        );
    }
}
