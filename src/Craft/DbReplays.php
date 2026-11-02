<?php

namespace facilo\portero\Craft;

use Craft;
use facilo\portero\Core\Ports\ReplayStore;

final class DbReplays implements ReplayStore
{
    public function remember(string $assertionHash, int $expiresAt): bool
    {
        try {
            Craft::$app->getDb()->createCommand()->insert('{{%portero_replay}}', compact('assertionHash', 'expiresAt'))->execute();
            return true;
        } catch (\yii\db\IntegrityException) {
            return false;
        }
    }
}
