<?php

namespace facilo\portero\Craft;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use facilo\portero\Core\Ports\SessionStore;

/**
 * Sessions a provider may later end, tied to Craft's own session token.
 * Holds hashes only, and only for connections that accept provider logout.
 */
final class DbSessions implements SessionStore
{
    private const TABLE = '{{%portero_sessions}}';
    private const ENDED_DAYS = 30;

    public function open(string $connectionUid, int $userId, string $nameHash, ?string $refHash): void
    {
        $token = Craft::$app->getUser()->getToken();
        if ($token === null) {
            return;
        }
        Craft::$app->getDb()->createCommand()->insert(self::TABLE, [
            'connectionUid' => $connectionUid,
            'nameHash' => $nameHash,
            'refHash' => $refHash,
            'craftToken' => $token,
            'userId' => $userId,
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
        ], false)->execute();
    }

    public function end(string $connectionUid, ?string $nameHash, array $refHashes): array
    {
        $query = (new Query())->select(['id', 'craftToken', 'userId'])->from(self::TABLE)->where(['connectionUid' => $connectionUid, 'dateEnded' => null]);
        if ($nameHash !== null) {
            $query->andWhere(['nameHash' => $nameHash]);
        }
        if ($refHashes !== []) {
            // A sign-in can come with no session ID. Named person, unnamed session: it could be this one, so it ends too.
            $query->andWhere($nameHash !== null ? ['or', ['refHash' => null], ['refHash' => $refHashes]] : ['refHash' => $refHashes]);
        }
        $rows = $query->all();
        if ($rows === []) {
            return [];
        }
        $db = Craft::$app->getDb()->createCommand();
        $db->update(self::TABLE, ['dateEnded' => Db::prepareDateForDb(new \DateTime())], ['id' => array_column($rows, 'id')], [], false)->execute();
        // Craft's own row goes too, or its identity cookie would bring the person straight back.
        $db->delete(Table::SESSIONS, ['token' => array_column($rows, 'craftToken')])->execute();
        return array_values(array_unique(array_map('intval', array_column($rows, 'userId'))));
    }

    public function isEnded(string $craftToken): bool
    {
        return (new Query())->from(self::TABLE)->where(['craftToken' => $craftToken])->andWhere(['not', ['dateEnded' => null]])->exists();
    }

    /**
     * Craft issues a new token each time its identity cookie restores a session, and the old one stays valid.
     * So both are tracked: a copy of the older cookie must die with the session too.
     */
    public function restored(string $oldToken, string $newToken): void
    {
        $row = (new Query())->select(['connectionUid', 'nameHash', 'refHash', 'userId'])->from(self::TABLE)->where(['craftToken' => $oldToken, 'dateEnded' => null])->one();
        if ($row !== null && $row !== false) {
            Craft::$app->getDb()->createCommand()->insert(self::TABLE, $row + ['craftToken' => $newToken, 'dateCreated' => Db::prepareDateForDb(new \DateTime())], false)->execute();
        }
    }

    public function collectGarbage(): void
    {
        $db = Craft::$app->getDb()->createCommand();
        $db->delete(self::TABLE, ['<', 'dateEnded', Db::prepareDateForDb(new \DateTime('-' . self::ENDED_DAYS . ' days'))])->execute();
        $live = (new Query())->select('token')->from(Table::SESSIONS);
        $db->delete(self::TABLE, ['and', ['dateEnded' => null], ['not in', 'craftToken', $live]])->execute();
    }
}
