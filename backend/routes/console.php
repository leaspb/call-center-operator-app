<?php

use App\Services\ChatAssignmentService;
use App\Services\OutboxPoller;
use App\Services\TelegramPollingService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('outbox:enqueue {--limit=50}', function (OutboxPoller $poller) {
    $count = $poller->enqueue((int) $this->option('limit'));
    $this->info("Enqueued {$count} due outbound job(s), including retries.");
});

Artisan::command('chats:auto-release {--timeout=10}', function (ChatAssignmentService $assignments) {
    $count = $assignments->autoReleaseInactive((int) $this->option('timeout'));
    $this->info("Auto-released {$count} inactive chat assignment(s).");
});

Artisan::command('telegram:poll {--once : Run one getUpdates request and exit} {--limit= : Max updates per request, 1-100} {--timeout= : Long polling timeout in seconds, 0-50}', function (TelegramPollingService $poller) {
    $limit = $this->option('limit') === null ? null : (int) $this->option('limit');
    $timeout = $this->option('timeout') === null ? null : (int) $this->option('timeout');

    do {
        $result = $poller->poll($limit, $timeout);
        if (($result['ok'] ?? false) !== true) {
            $this->error(($result['code'] ?? 'TELEGRAM_POLLING_FAILED').': '.($result['message'] ?? 'Telegram polling failed'));

            return 1;
        }

        $this->info(sprintf(
            'Telegram updates: received=%d processed=%d duplicates=%d ignored=%d failed=%d next_offset=%s',
            $result['received'],
            $result['processed'],
            $result['duplicates'],
            $result['ignored'],
            $result['failed'],
            $result['next_offset'] ?? 'none',
        ));

        if (! $this->option('once')) {
            sleep(1);
        }
    } while (! $this->option('once'));

    return 0;
});

Schedule::command('outbox:enqueue')->everyMinute()->withoutOverlapping();
Schedule::command('chats:auto-release')->everyMinute()->withoutOverlapping();

if (config('services.telegram.polling_enabled')) {
    Schedule::command('telegram:poll --once --timeout=0')->everyTenSeconds()->withoutOverlapping();
}
