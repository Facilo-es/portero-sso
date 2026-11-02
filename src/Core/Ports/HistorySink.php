<?php

namespace facilo\portero\Core\Ports;

interface HistorySink
{
    /** @param array{connectionUid:string, protocol:string, stage:string, outcome:string, reasonCode:?string, maskedEmail:?string, userId:?int} $attempt */
    public function record(array $attempt): void;
}
