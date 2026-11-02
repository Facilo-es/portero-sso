<?php

namespace facilo\portero\Craft;

use Craft;
use craft\db\Query;
use facilo\portero\Core\Ports\FlowStore;

final class DbFlows implements FlowStore
{
    private const TABLE = '{{%portero_flows}}';

    public function save(array $flow): void
    {
        Craft::$app->getDb()->createCommand()->insert(self::TABLE, $flow)->execute();
        // Anyone can start a sign-in. A flow lives ten minutes, so clearing dead rows here keeps the table to what's live, whatever the rate.
        if (random_int(1, 50) === 1) {
            Craft::$app->getDb()->createCommand()->delete(self::TABLE, ['<', 'expiresAt', time()])->execute();
        }
    }

    public function find(string $requestId): ?array
    {
        $row = (new Query())->from(self::TABLE)->where(['requestId' => $requestId])->one();
        if (!$row) {
            return null;
        }
        foreach (['expiresAt', 'usedAt'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }
        return $row;
    }

    public function consume(string $requestId, int $now): bool
    {
        return (bool) Craft::$app->getDb()->createCommand()
            ->update(self::TABLE, ['usedAt' => $now], ['requestId' => $requestId, 'usedAt' => null], [], false)
            ->execute();
    }
}
