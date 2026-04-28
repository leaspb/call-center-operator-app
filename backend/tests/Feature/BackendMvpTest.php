<?php

namespace Tests\Feature;

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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RetryingTelegramAdapter implements TelegramAdapter
{
    public function sendText(Chat $chat, Message $message): TelegramSendResult
    {
        return new TelegramSendResult(false, null, '429', 'Too Many Requests', true);
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
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertCreated()->assertJsonPath('user.role', 'admin')->assertJsonStructure(['token']);
        $user = User::firstOrFail();
        $this->assertTrue(Hash::check('secret123', $user->password));
        $this->assertNotSame('secret123', $user->password);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Second',
            'email' => 'second@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertForbidden()->assertJsonPath('code', 'REGISTRATION_CLOSED');
    }

    public function test_admin_can_create_grant_admin_and_reset_password(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'password' => 'old-secret']);
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/v1/admin/users', [
            'name' => 'Operator',
            'email' => 'operator@example.com',
            'password' => 'secret123',
            'role' => 'operator',
        ])->assertCreated()->json('user');

        $operator = User::findOrFail($created['id']);
        $this->assertTrue(Hash::check('secret123', $operator->password));

        $this->patchJson("/api/v1/admin/users/{$operator->id}/role", ['role' => 'admin'])
            ->assertOk()->assertJsonPath('user.role', 'admin');
        $this->postJson("/api/v1/admin/users/{$operator->id}/reset-password", ['password' => 'new-secret-123'])->assertOk();

        $operator->refresh();
        $this->assertSame('admin', $operator->role);
        $this->assertTrue(Hash::check('new-secret-123', $operator->password));
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'admin.user_role_changed', 'target_id' => $operator->id]);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'admin.user_password_reset', 'target_id' => $operator->id]);
    }

    public function test_telegram_webhook_requires_secret_and_is_idempotent_without_inbound_queue(): void
    {
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
        config(['services.telegram.webhook_secret' => '']);
        $payload = $this->telegramPayload(1002, 502, null) + [];
        unset($payload['message']['text']);
        $payload['message']['photo'] = [['file_id' => 'abc', 'width' => 10, 'height' => 10]];

        $this->postJson('/api/v1/telegram/webhook', $payload)
            ->assertOk()
            ->assertJsonPath('message.type', 'unsupported_message')
            ->assertJsonPath('message.body', null);
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

        $message = $chat->messages()->where('direction', 'inbound')->firstOrFail();
        $this->postJson("/api/v1/messages/{$message->id}/read")
            ->assertOk()
            ->assertJsonPath('message.read_by.0.user_id', $other->id);

        Sanctum::actingAs($owner);
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
            ->assertJsonStructure(['paths' => ['/auth/login', '/telegram/webhook']]);
    }

    private function makeChatWithInbound(string $body): Chat
    {
        $channel = Channel::create(['code' => 'telegram', 'name' => 'Telegram']);
        $external = $channel->externalUsers()->create([
            'external_id' => 'tg-1',
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

    private function telegramPayload(int $updateId, int $messageId, ?string $text): array
    {
        $message = [
            'message_id' => $messageId,
            'from' => ['id' => 12345, 'is_bot' => false, 'first_name' => 'Alex', 'last_name' => 'Smirnov', 'username' => 'alexs'],
            'chat' => ['id' => 12345, 'type' => 'private'],
            'date' => now()->timestamp,
        ];
        if ($text !== null) {
            $message['text'] = $text;
        }

        return ['update_id' => $updateId, 'message' => $message];
    }
}
