<?php

namespace facilo\portero\Craft;

use Craft;
use facilo\portero\Core\Ports\KeyCache;

final class CraftKeyCache implements KeyCache
{
    public function get(string $key): mixed
    {
        $value = Craft::$app->getCache()->get($key);
        return $value === false ? null : $value;
    }

    public function set(string $key, mixed $value, int $seconds): void
    {
        Craft::$app->getCache()->set($key, $value, $seconds);
    }
}
