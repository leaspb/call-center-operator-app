<?php

use App\Services\ChatAssignmentService;
use App\Services\OutboxPoller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('outbox:enqueue {--limit=50}', function (OutboxPoller $poller) {
    $count = $poller->enqueue((int) $this->option('limit'));
    $retryCount = $poller->enqueueDueRetries((int) $this->option('limit'));
    $this->info("Enqueued {$count} new outbound job(s) and {$retryCount} retry job(s).");
});

Artisan::command('chats:auto-release {--timeout=10}', function (ChatAssignmentService $assignments) {
    $count = $assignments->autoReleaseInactive((int) $this->option('timeout'));
    $this->info("Auto-released {$count} inactive chat assignment(s).");
});

Schedule::command('outbox:enqueue')->everyMinute();
Schedule::command('chats:auto-release')->everyMinute();
