<?php

namespace facilo\portero\Core;

/** Return addresses are a path on this site, or nothing. */
final class ReturnPath
{
    private const LIMIT = 1024;

    public static function safe(?string $path): ?string
    {
        if ($path === null || $path === '' || $path[0] !== '/' || strlen($path) > self::LIMIT) {
            return null;
        }
        $decoded = $path;
        for ($i = 0; $i < 3; $i++) {
            $decoded = rawurldecode($decoded);
        }
        if (strlen($decoded) > 1 && ($decoded[1] === '/' || $decoded[1] === '\\')) {
            return null;
        }
        if (str_contains($decoded, '\\') || preg_match('/[\x00-\x1f\x7f]/', $decoded) || str_contains($path, '://')) {
            return null;
        }
        return $path;
    }
}
