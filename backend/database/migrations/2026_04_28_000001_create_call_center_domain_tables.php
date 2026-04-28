<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->json('config')->nullable();
            $table->timestamps();
        });

        Schema::create('external_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('display_name');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['channel_id', 'external_id']);
        });

        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('external_user_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['open', 'assigned', 'closed'])->default('open');
            $table->foreignId('assigned_operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assignment_last_activity_at')->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamp('last_inbound_message_at')->nullable();
            $table->timestamps();
            $table->index(['channel_id', 'external_user_id', 'status']);
            $table->index(['status', 'assigned_operator_id']);
            $table->index('assignment_last_activity_at');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->enum('type', ['text', 'unsupported_message'])->default('text');
            $table->text('body')->nullable();
            $table->json('metadata')->nullable();
            $table->string('external_message_id')->nullable();
            $table->timestamps();
            $table->index(['chat_id', 'created_at', 'id']);
            $table->index(['direction', 'created_at']);
        });

        Schema::create('message_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['pending', 'queued', 'sending', 'retrying', 'sent', 'failed'])->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('provider_error_code')->nullable();
            $table->text('provider_error_message')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
        });

        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id();
            $table->string('aggregate_type');
            $table->unsignedBigInteger('aggregate_id');
            $table->string('event_type');
            $table->json('payload');
            $table->enum('status', ['pending', 'enqueued', 'processing', 'processed', 'failed'])->default('pending');
            $table->timestamp('available_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('locked_by')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
            $table->index(['locked_at', 'locked_by']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });

        Schema::create('message_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->timestamps();
            $table->unique(['message_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'message_id']);
        });

        Schema::create('processed_provider_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_update_id');
            $table->string('raw_payload_hash', 64);
            $table->timestamp('processed_at');
            $table->timestamps();
            $table->unique(['channel_id', 'provider', 'provider_update_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type');
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['actor_user_id', 'created_at']);
            $table->index(['target_type', 'target_id', 'created_at']);
            $table->index(['event_type', 'created_at']);
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('processed_provider_updates');
        Schema::dropIfExists('message_reads');
        Schema::dropIfExists('outbox_messages');
        Schema::dropIfExists('message_deliveries');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('chats');
        Schema::dropIfExists('external_users');
        Schema::dropIfExists('channels');
    }
};
