<?php

namespace facilo\portero\Craft;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use facilo\portero\Core\Ports\HistorySink;

final class DbHistory implements HistorySink
{
    private const TABLE = '{{%portero_attempts}}';
    private const REPEAT_SECONDS = 60;

    public function record(array $attempt): void
    {
        if ($this->isRepeat($attempt)) {
            return;
        }
        Craft::$app->getDb()->createCommand()->insert(self::TABLE, $attempt + ['dateCreated' => Db::prepareDateForDb(new \DateTime())], false)->execute();
    }

    /** A row is cheap to cause, so only one refusal a minute of each kind is kept, for each person or for nobody in particular. */
    private function isRepeat(array $attempt): bool
    {
        if ($attempt['outcome'] !== 'refused') {
            return false;
        }
        return (new Query())->from(self::TABLE)->where([
            'connectionUid' => $attempt['connectionUid'],
            'reasonCode' => $attempt['reasonCode'],
            'maskedEmail' => $attempt['maskedEmail'],
        ])->andWhere(['>', 'dateCreated', Db::prepareDateForDb(new \DateTime('-' . self::REPEAT_SECONDS . ' seconds'))])->exists();
    }
}
