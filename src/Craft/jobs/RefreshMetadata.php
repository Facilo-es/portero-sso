<?php

namespace facilo\portero\Craft\jobs;

use Craft;
use craft\queue\BaseJob;
use facilo\portero\Plugin;

class RefreshMetadata extends BaseJob
{
    public function execute($queue): void
    {
        $metadata = Plugin::getInstance()->metadata;
        $metadata->refreshAll();
        $metadata->scheduleNext();
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('portero-sso', 'Updating provider details');
    }
}
