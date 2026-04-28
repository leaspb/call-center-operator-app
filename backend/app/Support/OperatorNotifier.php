<?php

namespace App\Support;

use App\Events\OperatorEvent;
use Illuminate\Support\Facades\DB;

class OperatorNotifier
{
    public function notify(string $name, array $payload, array $recipientUserIds): void
    {
        $dispatch = fn () => event(new OperatorEvent($name, $payload, $recipientUserIds));

        if (DB::connection()->transactionLevel() > 0) {
            DB::afterCommit($dispatch);

            return;
        }

        $dispatch();
    }
}
