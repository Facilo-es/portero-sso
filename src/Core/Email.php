<?php

namespace facilo\portero\Core;

final class Email
{
    public static function normalize(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    /** A character such as the Kelvin sign lowercases to a plain letter, so it would match someone else's address. */
    public static function foldsToAscii(?string $email): bool
    {
        foreach (preg_split('//u', (string) $email, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if (strlen($character) > 1 && preg_match('/[\x00-\x7f]/', mb_strtolower($character))) {
                return true;
            }
        }
        return false;
    }

    public static function domain(string $email): string
    {
        $at = strrpos($email, '@');
        return $at === false ? '' : self::normalize(substr($email, $at + 1));
    }

    /** @param string[] $allowed */
    public static function domainAllowed(string $email, array $allowed): bool
    {
        return in_array(self::domain($email), array_map(self::normalize(...), $allowed), true);
    }

    /** el***@example.com */
    public static function mask(?string $email): ?string
    {
        $at = strrpos((string) $email, '@');
        if ($at === false) {
            return null;
        }
        return mb_substr((string) $email, 0, min(2, $at)) . '***' . substr((string) $email, $at);
    }
}
