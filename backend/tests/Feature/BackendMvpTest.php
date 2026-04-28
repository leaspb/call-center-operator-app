<?php

namespace Tests\Feature;

use App\Events\OperatorEvent;
use App\Jobs\SendOutboundMessage;
use App\Models\Channel;
use App\Models\Chat;
use App\Models\Message;
use App\Models\MessageDelivery;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ChatAssignmentService;
use App\Services\OutboxPoller;
use App\Services\Telegram\TelegramAdapter;
use App\Services\Telegram\TelegramSendResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class RetryingTelegramAdapter implements TelegramAdapter
{
    public function sendText(Chat $chat, Message $message): TelegramSendResult
    {
        return new TelegramSendResult(false, null, '429', 'Too Many Requests', true);
    }
}

class ThrowingTelegramAdapter implements TelegramAdapter
{
    public function sendText(Chat $chat, Message $message): TelegramSendResult
    {
        throw new RuntimeException('POST https://api.telegram.org/bot123456:ABC-secret-token/sendMessage failed');
    }
}

class BackendMvpTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_registration_creates_admin_and_subsequent_registration_is_blocked(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'First Admin',
            'email' => 'admin@example.com',
            'password' => 'StrongPass123',
            'password_confirmation' => 'StrongPass123',
        ]);

        $response->assertCreated()->assertJsonPath('user.role', 'admin')->assertJsonStructure(['token']);
        $user = User::firstOrFail();
        $this->assertSame('first-admin', $user->bootstrap_admin_key);
        $this->assertTrue(Hash::check('StrongPass123', $user->password));
        $this->assertNotSame('StrongPass123', $user->password);
        $this->assertNotNull(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->value('expires_at'));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Second',
            'email' => 'second@example.com',
            'password' => 'StrongPass123',
            'password_confirmation' => 'StrongPass123',
        ])->assertForbidden()->assertJsonPath('code', 'REGISTRATION_CLOSED');
    }

    public function test_security_headers_are_returned_on_api_responses(): void
    {
        $this->getJson('/api/v1/openapi.json')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Content-Security-Policy', "default-src 'self'; frame-ancestors 'none'; base-uri 'none'");
    }

    public function test_admin_can_create_grant_admin_and_reset_password(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'password' => 'OldSecret123']);
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/v1/admin/users', [
            'name' => 'Operator',
            'email' => 'operator@example.com',
            'password' => 'StrongPass123',
            'role' => 'operator',
        ])->assertCreated()->json('user');

        $operator = User::findOrFail($created['id']);
        $this->assertTrue(Hash::check('StrongPass123', $operator->password));

        $this->patchJson("/api/v1/admin/users/{$operator->id}/role", ['role' => 'admin'])
            ->assertOk()->assertJsonPath('user.role', 'admin');
        $this->postJson("/api/v1/admin/users/{$operator->id}/reset-password", ['password' => 'NewSecret1234'])->assertOk();
        $this->patchJson("/api/v1/admin/users/{$operator->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('user.is_active', false);

        $operator->refresh();
        $this->assertSame('admin', $operator->role);
        $this->assertFalse($operator->is_active);
        $this->assertTrue(Hash::check('NewSecret1234', $operator->password));
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'admin.user_role_changed', 'target_id' => $operator->id]);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'admin.user_password_reset', 'target_id' => $operator->id]);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'admin.user_status_changed', 'target_id' => $operator->id]);
    }

    public function test_admin_cannot_remove_the_last_active_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/users/{$admin->id}/role", ['role' => 'operator'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'LAST_ADMIN_REQUIRED');
        $this->patchJson("/api/v1/admin/users/{$admin->id}/status", ['is_active' => false])
            ->assertStatus(409)
            ->assertJsonPath('code', 'LAST_ADMIN_REQUIRED');

        $backupAdmin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->patchJson("/api/v1/admin/users/{$admin->id}/role", ['role' => 'operator'])
            ->assertOk()
            ->assertJsonPath('user.role', 'operator');
        $this->assertSame('admin', $backupAdmin->refresh()->role);
        $this->assertTrue($backupAdmin->is_active);
    }

    public function test_telegram_webhook_requires_secret_and_is_idempotent_without_inbound_queue(): void
    {
        config(['services.telegram.webhook_secret' => '']);
        $this->postJson('/api/v1/telegram/webhook', $this->telegramPayload(1000, 500, 'No secret'))
            ->assertStatus(500)
            ->assertJsonPath('code', 'MISCONFIGURATION');

        config(['services.telegram.webhook_secret' => 'test-secret']);
        $payload = $this->telegramPayload(1001, 501, 'Hello');

        $this->postJson('/api/v1/telegram/webhook', $payload)->assertForbidden()->assertJsonPath('code', 'INVALID_TELEGRAM_WEBHOOK_SECRET');

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/v1/telegram/webhook', $payload)
            ->assertOk()
            ->assertJsonPath('message.body', 'Hello')
            ->assertJsonPath('chat.status', 'open')
            ->assertJsonPath('chat.assignment_state', 'unassigned');

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/v1/telegram/webhook', $payload)
            ->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(1, Message::where('direction', 'inbound')->count());
        $this->assertDatabaseHas('processed_provider_updates', ['provider' => 'telegram', 'provider_update_id' => '1001']);
    }

    public function test_unsupported_telegram_media_is_stored_as_placeholder_message(): void
    {
        config(['services.telegram.webhook_secret' => 'test-secret']);
        $payload = $this->telegramPayload(1002, 502, null) + [];
        unset($payload['message']['text']);
        $payload['message']['photo'] = [['file_id' => 'abc', 'width' => 10, 'height' => 10]];

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/v1/telegram/webhook', $payload)
            ->assertOk()
            ->assertJsonPath('message.type', 'unsupported_message')
            ->assertJsonPath('message.body', null);
    }

    public function test_dev_telegram_replay_is_not_enabled_by_production_debug_flag(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config(['app.debug' => true, 'services.telegram.dev_replay_enabled' => false, 'services.telegram.dev_replay_secret' => 'replay-secret']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/v1/dev/telegram/updates/simulate', $this->telegramPayload(1003, 503, 'Debug must not enable dev replay'))
            ->assertForbidden()
            ->assertJsonPath('code', 'DEV_ENDPOINT_DISABLED');

        config(['services.telegram.dev_replay_enabled' => true]);
        $this->postJson('/api/v1/dev/telegram/updates/simulate', $this->telegramPayload(1004, 504, 'Explicit dev replay enabled'))
            ->assertForbidden()
            ->assertJsonPath('code', 'INVALID_DEV_REPLAY_SECRET');

        $this->withHeader('X-Dev-Telegram-Replay-Secret', 'replay-secret')
            ->postJson('/api/v1/dev/telegram/updates/simulate', $this->telegramPayload(1004, 504, 'Explicit dev replay enabled'))
            ->assertOk()
            ->assertJsonPath('message.body', 'Explicit dev replay enabled');
        app()->detectEnvironment(fn () => 'testing');
    }

    public function test_disabled_user_with_existing_token_cannot_use_authenticated_api(): void
    {
        $operator = User::factory()->create(['role' => 'operator', 'is_active' => false]);
        $chat = $this->makeChatWithInbound('Blocked user');
        Sanctum::actingAs($operator);

        $this->getJson('/api/v1/chats')
            ->assertForbidden()
            ->assertJsonPath('code', 'USER_DISABLED');

        $this->postJson("/api/v1/chats/{$chat->id}/messages", ['body' => 'Should not send'])
            ->assertForbidden()
            ->assertJsonPath('code', 'USER_DISABLED');

        $this->assertSame(0, Message::where('direction', 'outbound')->count());
    }

    public function test_operator_cannot_close_unassigned_chat_without_owning_it(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $admin = User::factory()->create(['role' => 'admin']);
        $chat = $this->makeChatWithInbound('Needs owner');

        Sanctum::actingAs($operator);
        $this->postJson("/api/v1/chats/{$chat->id}/close")
            ->assertForbidden()
            ->assertJsonPath('code', 'CHAT_NOT_OWNED');
        $this->assertSame('open', $chat->refresh()->status);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/chats/{$chat->id}/close")
            ->assertOk()
            ->assertJsonPath('chat.status', 'closed');
    }

    public function test_assignment_conflict_read_receipts_and_outbound_transactional_outbox(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['role' => 'operator']);
        $other = User::factory()->create(['role' => 'operator']);
        $chat = $this->makeChatWithInbound('Hi');

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/chats/{$chat->id}/assign")
            ->assertOk()
            ->assertJsonPath('chat.status', 'assigned')
            ->assertJsonPath('chat.assigned_operator.id', $owner->id);

        Sanctum::actingAs($other);
        $this->postJson("/api/v1/chats/{$chat->id}/assign")
            ->assertStatus(409)
            ->assertJsonPath('code', 'CHAT_ALREADY_ASSIGNED');
        $this->getJson('/api/v1/chats?filter=all')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/chats?filter=assigned_to_others')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/chats/{$chat->id}/messages", ['body' => 'Admin bypass'])
            ->assertForbidden()
            ->assertJsonPath('code', 'CHAT_NOT_OWNED');
        $this->getJson("/api/v1/chats/{$chat->id}")
            ->assertOk()
            ->assertJsonPath('chat.read_only', true);

        $this->postJson("/api/v1/admin/chats/{$chat->id}/assign", ['operator_id' => $admin->id])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        Sanctum::actingAs($other);
        $message = $chat->messages()->where('direction', 'inbound')->firstOrFail();
        $this->postJson("/api/v1/messages/{$message->id}/read")
            ->assertForbidden()
            ->assertJsonPath('code', 'CHAT_NOT_VISIBLE');
        $this->getJson("/api/v1/chats/{$chat->id}/messages")
            ->assertForbidden()
            ->assertJsonPath('code', 'CHAT_NOT_VISIBLE');
        $this->getJson("/api/v1/chats/{$chat->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'CHAT_NOT_VISIBLE');

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/messages/{$message->id}/read")
            ->assertOk()
            ->assertJsonPath('message.read_by.0.user_id', $owner->id);

        $outbound = $this->postJson("/api/v1/chats/{$chat->id}/messages", ['body' => 'Answer'])
            ->assertCreated()
            ->assertJsonPath('message.delivery_status', 'pending')
            ->assertJsonPath('delivery.status', 'pending')
            ->json();

        $this->assertDatabaseHas('messages', ['id' => $outbound['message']['id'], 'direction' => 'outbound', 'operator_id' => $owner->id]);
        $this->assertDatabaseHas('message_deliveries', ['id' => $outbound['delivery']['id'], 'status' => 'pending']);
        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'outbound.message.created', 'aggregate_id' => $outbound['message']['id'], 'status' => 'pending']);

        $count = app(OutboxPoller::class)->enqueue();
        $this->assertSame(1, $count);
        Queue::assertPushed(SendOutboundMessage::class);
        $outbox = OutboxMessage::where('aggregate_id', $outbound['message']['id'])->firstOrFail();
        $this->assertSame('enqueued', $outbox->status);

        Carbon::setTestNow(now()->addMinutes(3));
        $this->assertSame(1, app(OutboxPoller::class)->enqueue(), 'stale enqueued outbox must be re-enqueued after Redis job loss before worker claim');
        Carbon::setTestNow();
    }

    public function test_closed_chat_is_audited_and_reopened_by_new_inbound_message(): void
    {
        config(['services.telegram.webhook_secret' => 'test-secret']);
        $operator = User::factory()->create(['role' => 'operator']);
        $other = User::factory()->create(['role' => 'operator']);
        $chat = $this->makeChatWithInbound('Close me', '990001');
        $chat->forceFill([
            'status' => 'assigned',
            'assigned_operator_id' => $operator->id,
            'assigned_by_user_id' => $operator->id,
            'assigned_at' => now(),
            'assignment_last_activity_at' => now(),
        ])->save();

        Sanctum::actingAs($operator);
        $this->postJson("/api/v1/chats/{$chat->id}/close")
            ->assertOk()
            ->assertJsonPath('chat.status', 'closed');
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'chat.closed', 'target_id' => $chat->id]);

        Sanctum::actingAs($other);
        $message = $chat->messages()->where('direction', 'inbound')->firstOrFail();
        $this->postJson("/api/v1/messages/{$message->id}/read")
            ->assertForbidden()
            ->assertJsonPath('code', 'CHAT_NOT_VISIBLE');

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-secret')
            ->postJson('/api/v1/telegram/webhook', $this->telegramPayload(2001, 901, 'I am back', 990001))
            ->assertOk()
            ->assertJsonPath('chat.status', 'open')
            ->assertJsonPath('chat.assignment_state', 'unassigned');

        $chat->refresh();
        $this->assertSame('open', $chat->status);
        $this->assertNull($chat->assigned_operator_id);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'chat.reopened', 'target_id' => $chat->id]);
    }

    public function test_chat_cursor_pagination_uses_last_returned_chat_without_skipping_rows(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        Sanctum::actingAs($operator);
        $first = $this->makeChatWithInbound('First');
        $second = $this->makeChatWithInbound('Second');
        $third = $this->makeChatWithInbound('Third');
        $first->forceFill(['last_message_at' => now()->subMinutes(3), 'last_inbound_message_at' => now()->subMinutes(3)])->save();
        $second->forceFill(['last_message_at' => now()->subMinutes(2), 'last_inbound_message_at' => now()->subMinutes(2)])->save();
        $third->forceFill(['last_message_at' => now()->subMinute(), 'last_inbound_message_at' => now()->subMinute()])->save();

        $pageOne = $this->getJson('/api/v1/chats?filter=all&limit=2')
            ->assertOk()
            ->assertJsonPath('data.0.id', $third->id)
            ->assertJsonPath('data.1.id', $second->id)
            ->json();

        $this->assertNotNull($pageOne['next_cursor']);
        $this->assertIsArray($pageOne['next_cursor']);
        $this->assertSame($second->id, $pageOne['next_cursor']['id']);
        $this->getJson('/api/v1/chats?filter=all&limit=2&cursor='.urlencode(json_encode($pageOne['next_cursor'], JSON_THROW_ON_ERROR)))
            ->assertOk()
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('next_cursor', null);
        $this->getJson('/api/v1/chats?filter=all&limit=2&cursor='.urlencode('{"id":"bad","inbound_null":false}'))
            ->assertUnprocessable();
        $this->getJson('/api/v1/chats?filter=all&limit=2&cursor='.$second->id)
            ->assertUnprocessable();
    }

    public function test_message_cursor_pagination_uses_last_returned_message_without_skipping_rows(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        Sanctum::actingAs($operator);
        $chat = $this->makeChatWithInbound('One');
        $messageTwo = $chat->messages()->create(['direction' => 'inbound', 'type' => 'text', 'body' => 'Two']);
        $messageThree = $chat->messages()->create(['direction' => 'inbound', 'type' => 'text', 'body' => 'Three']);

        $pageOne = $this->getJson("/api/v1/chats/{$chat->id}/messages?limit=2")
            ->assertOk()
            ->assertJsonPath('data.0.id', $messageThree->id)
            ->assertJsonPath('data.1.id', $messageTwo->id)
            ->json();

        $this->assertSame($messageTwo->id, $pageOne['next_cursor']);
        $this->getJson("/api/v1/chats/{$chat->id}/messages?limit=2&before_id={$pageOne['next_cursor']}")
            ->assertOk()
            ->assertJsonPath('data.0.body', 'One')
            ->assertJsonPath('next_cursor', null);
    }

    public function test_outbox_recovers_stale_processing_worker_claim(): void
    {
        Queue::fake();
        $operator = User::factory()->create(['role' => 'operator']);
        $chat = $this->makeChatWithInbound('Hi');
        $chat->forceFill([
            'status' => 'assigned',
            'assigned_operator_id' => $operator->id,
            'assigned_by_user_id' => $operator->id,
            'assigned_at' => now(),
            'assignment_last_activity_at' => now(),
        ])->save();
        Sanctum::actingAs($operator);
        $response = $this->postJson("/api/v1/chats/{$chat->id}/messages", ['body' => 'Recover me'])->assertCreated()->json();
        $delivery = MessageDelivery::findOrFail($response['delivery']['id']);
        $outbox = OutboxMessage::where('aggregate_id', $response['message']['id'])->firstOrFail();

        $delivery->forceFill(['status' => 'sending'])->save();
        $outbox->forceFill(['status' => 'processing', 'locked_at' => now()->subMinutes(3), 'locked_by' => 'lost-worker'])->save();

        $this->assertSame(0, app(OutboxPoller::class)->enqueue());
        $delivery->refresh();
        $outbox->refresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(0, $delivery->attempt_count);
        $this->assertSame('WORKER_RESULT_UNKNOWN', $delivery->provider_error_code);
        $this->assertSame('failed', $outbox->status);
        $this->assertNull($outbox->available_at);

        Carbon::setTestNow(now()->addHour());
        $this->assertSame(0, app(OutboxPoller::class)->enqueue());
        Queue::assertNothingPushed();
        Carbon::setTestNow();
    }

    public function test_send_job_ignores_stale_outbox_claim_token(): void
    {
        config(['services.telegram.fake' => true]);
        $operator = User::factory()->create(['role' => 'operator']);
        $chat = $this->makeChatWithInbound('Hi');
        $message = $chat->messages()->create(['direction' => 'outbound', 'operator_id' => $operator->id, 'type' => 'text', 'body' => 'Stale worker']);
        $delivery = $message->deliveries()->create(['channel_id' => $chat->channel_id, 'status' => 'queued', 'attempt_count' => 0]);
        $outbox = $message->outboxMessages()->create([
            'aggregate_type' => 'message',
            'aggregate_id' => $message->id,
            'event_type' => 'outbound.message.created',
            'payload' => ['message_id' => $message->id, 'delivery_id' => $delivery->id],
            'status' => 'enqueued',
            'available_at' => null,
            'locked_by' => 'current-worker',
            'locked_at' => now(),
        ]);

        (new SendOutboundMessage($outbox->id, 'stale-worker'))->handle(app(TelegramAdapter::class), app(AuditLogger::class));

        $this->assertSame('enqueued', $outbox->refresh()->status);
        $this->assertSame('current-worker', $outbox->locked_by);
        $this->assertSame('queued', $delivery->refresh()->status);
        $this->assertNull($delivery->provider_message_id);
    }

    public function test_auto_release_after_ten_minutes_and_heartbeat_prevents_it(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $chat = $this->makeChatWithInbound('Need help');
        $chat->forceFill([
            'status' => 'assigned',
            'assigned_operator_id' => $operator->id,
            'assigned_by_user_id' => $operator->id,
            'assigned_at' => now()->subMinutes(9),
            'assignment_last_activity_at' => now()->subMinutes(9),
        ])->save();

        $released = app(ChatAssignmentService::class)->autoReleaseInactive(10);
        $this->assertSame(0, $released);

        $chat->forceFill(['assignment_last_activity_at' => now()->subMinutes(11)])->save();
        $released = app(ChatAssignmentService::class)->autoReleaseInactive(10);
        $this->assertSame(1, $released);
        $chat->refresh();
        $this->assertSame('open', $chat->status);
        $this->assertNull($chat->assigned_operator_id);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'chat.auto_released', 'target_id' => $chat->id]);
    }

    public function test_send_job_marks_fake_delivery_sent_and_retry_backoff_is_exact(): void
    {
        config(['services.telegram.fake' => true]);
        $operator = User::factory()->create(['role' => 'operator']);
        $chat = $this->makeChatWithInbound('Hi');
        $chat->forceFill([
            'status' => 'assigned',
            'assigned_operator_id' => $operator->id,
            'assigned_by_user_id' => $operator->id,
            'assigned_at' => now(),
            'assignment_last_activity_at' => now(),
        ])->save();
        Sanctum::actingAs($operator);
        $response = $this->postJson("/api/v1/chats/{$chat->id}/messages", ['body' => 'Answer'])->assertCreated()->json();
        $delivery = MessageDelivery::findOrFail($response['delivery']['id']);
        $outbox = OutboxMessage::where('aggregate_id', $response['message']['id'])->firstOrFail();
        $delivery->forceFill(['status' => 'queued'])->save();
        $outbox->forceFill(['status' => 'enqueued'])->save();

        (new SendOutboundMessage($outbox->id))->handle(app(TelegramAdapter::class), app(AuditLogger::class));
        $delivery->refresh();
        $this->assertSame('sent', $delivery->status);
        $this->assertSame('fake-'.$delivery->message_id, $delivery->provider_message_id);
        $this->assertSame('processed', $outbox->refresh()->status);

        $this->app->bind(TelegramAdapter::class, fn () => new RetryingTelegramAdapter);
        $delivery->forceFill(['status' => 'queued', 'attempt_count' => 0, 'provider_message_id' => null])->save();
        $outbox->forceFill(['status' => 'enqueued', 'available_at' => null])->save();
        $base = Carbon::parse('2026-04-28T10:00:00Z');
        Carbon::setTestNow($base);
        foreach ([1, 2, 5, 10, 30] as $idx => $minutes) {
            (new SendOutboundMessage($outbox->id))->handle(app(TelegramAdapter::class), app(AuditLogger::class));
            $delivery->refresh();
            $this->assertSame('retrying', $delivery->status);
            $this->assertSame($idx + 1, $delivery->attempt_count);
            $this->assertTrue($delivery->next_attempt_at->equalTo($base->copy()->addMinutes($minutes)));
            $this->assertSame('pending', $outbox->refresh()->status);
            $this->assertTrue($outbox->available_at->equalTo($delivery->next_attempt_at));
            $outbox->forceFill(['status' => 'enqueued', 'available_at' => null])->save();
        }

        (new SendOutboundMessage($outbox->id))->handle(app(TelegramAdapter::class), app(AuditLogger::class));
        $delivery->refresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(6, $delivery->attempt_count);
        $this->assertSame('failed', $outbox->refresh()->status);
        Carbon::setTestNow();
    }

    public function test_send_job_turns_transport_exception_into_retryable_outbox_state(): void
    {
        $this->app->bind(TelegramAdapter::class, fn () => new ThrowingTelegramAdapter);
        $operator = User::factory()->create(['role' => 'operator']);
        $chat = $this->makeChatWithInbound('Hi');
        $chat->forceFill([
            'status' => 'assigned',
            'assigned_operator_id' => $operator->id,
            'assigned_by_user_id' => $operator->id,
            'assigned_at' => now(),
            'assignment_last_activity_at' => now(),
        ])->save();
        $message = $chat->messages()->create(['direction' => 'outbound', 'operator_id' => $operator->id, 'type' => 'text', 'body' => 'Exception retry']);
        $delivery = $message->deliveries()->create(['channel_id' => $chat->channel_id, 'status' => 'queued', 'attempt_count' => 0]);
        $outbox = $message->outboxMessages()->create([
            'aggregate_type' => 'message',
            'aggregate_id' => $message->id,
            'event_type' => 'outbound.message.created',
            'payload' => ['message_id' => $message->id, 'delivery_id' => $delivery->id],
            'status' => 'enqueued',
            'available_at' => null,
        ]);
        $base = Carbon::parse('2026-04-28T12:00:00Z');
        Carbon::setTestNow($base);

        (new SendOutboundMessage($outbox->id))->handle(app(TelegramAdapter::class), app(AuditLogger::class));

        $delivery->refresh();
        $outbox->refresh();
        $this->assertSame('retrying', $delivery->status);
        $this->assertSame(1, $delivery->attempt_count);
        $this->assertSame('TRANSPORT_EXCEPTION', $delivery->provider_error_code);
        $this->assertSame('Telegram transport failed; see server logs', $delivery->provider_error_message);
        $this->assertStringNotContainsString('ABC-secret-token', $delivery->provider_error_message);
        $this->assertTrue($delivery->next_attempt_at->equalTo($base->copy()->addMinute()));
        $this->assertSame('pending', $outbox->status);
        $this->assertTrue($outbox->available_at->equalTo($delivery->next_attempt_at));
        Carbon::setTestNow();
    }

    public function test_manual_retry_recreates_pending_outbox_transactionally(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $operator = User::factory()->create(['role' => 'operator']);
        $chat = $this->makeChatWithInbound('Hi');
        $chat->forceFill([
            'status' => 'assigned',
            'assigned_operator_id' => $operator->id,
            'assigned_by_user_id' => $operator->id,
            'assigned_at' => now(),
            'assignment_last_activity_at' => now(),
        ])->save();
        $message = $chat->messages()->create(['direction' => 'outbound', 'operator_id' => $operator->id, 'type' => 'text', 'body' => 'Retry me']);
        $delivery = $message->deliveries()->create(['channel_id' => $chat->channel_id, 'status' => 'failed', 'attempt_count' => 6]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/deliveries/{$delivery->id}/retry")
            ->assertOk()
            ->assertJsonPath('delivery.status', 'pending');

        $this->assertDatabaseHas('message_deliveries', ['id' => $delivery->id, 'status' => 'pending']);
        $this->assertDatabaseHas('outbox_messages', ['aggregate_id' => $message->id, 'status' => 'pending']);
    }

    public function test_manual_retry_rechecks_retryability_under_lock(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $operator = User::factory()->create(['role' => 'operator']);
        $chat = $this->makeChatWithInbound('Hi');
        $message = $chat->messages()->create(['direction' => 'outbound', 'operator_id' => $operator->id, 'type' => 'text', 'body' => 'Already sent']);
        $delivery = $message->deliveries()->create(['channel_id' => $chat->channel_id, 'status' => 'sent', 'attempt_count' => 1]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/deliveries/{$delivery->id}/retry")
            ->assertStatus(409)
            ->assertJsonPath('code', 'DELIVERY_NOT_RETRYABLE')
            ->assertJsonPath('details.status', 'sent');

        $this->assertDatabaseHas('message_deliveries', ['id' => $delivery->id, 'status' => 'sent']);
        $this->assertDatabaseMissing('outbox_messages', ['aggregate_id' => $message->id, 'status' => 'pending']);
    }

    public function test_openapi_contract_is_available(): void
    {
        $this->getJson('/api/v1/openapi.json')
            ->assertOk()
            ->assertJsonPath('openapi', '3.0.3')
            ->assertJsonPath('components.schemas.ChatCursor.type', 'object')
            ->assertJsonPath('paths./chats.get.parameters.2.name', 'cursor')
            ->assertJsonStructure(['paths' => ['/auth/login', '/telegram/webhook']]);
    }

    public function test_operator_event_recipients_are_not_broadcast_payload(): void
    {
        $event = new OperatorEvent('message.created', ['chat_id' => 7], [42, 42, 99]);

        $this->assertSame('message.created', $event->broadcastAs());
        $this->assertSame(['chat_id' => 7], $event->broadcastWith());
        $this->assertSame(
            ['private-operator.42', 'private-operator.99'],
            array_map('strval', $event->broadcastOn())
        );
    }

    private function makeChatWithInbound(string $body, ?string $externalId = null): Chat
    {
        $channel = Channel::firstOrCreate(['code' => 'telegram'], ['name' => 'Telegram']);
        $external = $channel->externalUsers()->create([
            'external_id' => $externalId ?? uniqid('tg-', true),
            'display_name' => 'Telegram User',
            'username' => 'tguser',
        ]);
        $chat = Chat::create([
            'channel_id' => $channel->id,
            'external_user_id' => $external->id,
            'status' => 'open',
            'last_message_at' => now(),
            'last_inbound_message_at' => now(),
        ]);
        $chat->messages()->create(['direction' => 'inbound', 'type' => 'text', 'body' => $body, 'metadata' => []]);

        return $chat;
    }

    private function telegramPayload(int $updateId, int $messageId, ?string $text, int $externalId = 12345): array
    {
        $message = [
            'message_id' => $messageId,
            'from' => ['id' => $externalId, 'is_bot' => false, 'first_name' => 'Alex', 'last_name' => 'Smirnov', 'username' => 'alexs'],
            'chat' => ['id' => $externalId, 'type' => 'private'],
            'date' => now()->timestamp,
        ];
        if ($text !== null) {
            $message['text'] = $text;
        }

        return ['update_id' => $updateId, 'message' => $message];
    }
}
